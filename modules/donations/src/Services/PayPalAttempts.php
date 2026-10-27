<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;

/**
 * Local REST-attempt reservation only. Does NOT make PayPal HTTP requests,
 * confirm a transaction or credit Wallet. Network work belongs strictly
 * BETWEEN these short transactions (iterations 2 and 4).
 */
final class PayPalAttempts
{
    /** Preserve one active attempt per local order and merchant. */
    public function reserveLive(DonationPaymentPreparation $preparation, string $merchantIdentity): PayPalAttempt
    {
        self::assertMerchant($merchantIdentity);

        return DB::transaction(function () use ($preparation, $merchantIdentity): PayPalAttempt {
            $candidate = DonationOrder::query()->whereKey($preparation->order_id)->firstOrFail();
            User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $order = DonationOrder::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $prepared = DonationPaymentPreparation::query()->whereKey($preparation->id)->lockForUpdate()->firstOrFail();
            if ($prepared->provider_code !== 'paypal' || $prepared->mode !== 'live'
                || (int) $prepared->order_id !== (int) $order->id
                || $prepared->provider_label !== $order->order_uuid
                || (int) $prepared->payer_amount_minor !== (int) $order->total_minor
                || $prepared->currency_code !== $order->currency_code
                || $order->status !== DonationOrder::DRAFT || $order->expires_at === null || $order->expires_at->isPast()
                || DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->exists()) {
                throw new LogicException('Order cannot reserve a PayPal attempt.');
            }
            // Locking the order serializes inserts even if there is no attempt row yet.
            $existing = PayPalAttempt::query()->where('order_id', $order->id)
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->purpose !== 'player' || $existing->environment !== 'live'
                    || $existing->merchant_identity !== $merchantIdentity
                    || (int) $existing->preparation_id !== (int) $prepared->id
                    || (int) $existing->gross_amount_minor !== (int) $order->total_minor
                    || $existing->currency_code !== $order->currency_code
                    || (int) $existing->exponent !== (int) $order->exponent) {
                    // Changing merchant/config does NOT silently replace an ambiguous attempt.
                    throw new LogicException('PayPal attempt requires administrator review.');
                }

                return $existing; // Stable Create/Capture request IDs and invoice ID.
            }

            $attempt = new PayPalAttempt;
            $attempt->forceFill([
                'order_id' => $order->id, 'preparation_id' => $prepared->id,
                'purpose' => 'player', 'environment' => 'live',
                'merchant_identity' => $merchantIdentity, 'status' => 'reserved',
                'create_request_id' => (string) Str::uuid(),
                'capture_request_id' => (string) Str::uuid(),
                'invoice_id' => 'KC-'.Str::uuid(),
                'gross_amount_minor' => (int) $order->total_minor,
                'currency_code' => (string) $order->currency_code,
                'exponent' => (int) $order->exponent,
            ])->save();

            return $attempt;
        }, 3);
    }

    /** Claim Create before the HTTP call. Another concurrent worker MUST NOT POST. */
    public function claimCreate(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status === 'reserved') {
                DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                    'status' => 'creating', 'create_started_at' => now(), 'updated_at' => now(),
                ]);

                return true;
            }
            // The ONLY permitted Create retry uses the SAME persisted request ID
            // inside the default 6h window with a 10-minute safety margin after timeout.
            if ($attempt->status === 'create_unknown' && $attempt->paypal_order_id === null
                && $attempt->create_started_at !== null
                && $attempt->create_started_at->greaterThan(now()->subMinutes(350))) {
                DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                    'status' => 'creating', 'updated_at' => now(),
                ]);

                return true;
            }

            return false;
        }, 3);
    }

    /** Mark a timeout as unknown; never start a fresh request with a new key. */
    public function markCreateUnknown(int $attemptId, string $reason = 'api_timeout'): void
    {
        $this->transition($attemptId, ['creating'], 'create_unknown', $reason);
    }

    /** Save the stable remote ID; unique across environment and merchant. */
    public function markCreated(int $attemptId, string $remoteOrderId, ?string $approvalUrl = null): void
    {
        self::assertRemoteId($remoteOrderId);
        if ($approvalUrl !== null && strlen($approvalUrl) > 2048) {
            throw new InvalidArgumentException('Invalid PayPal approval link.');
        }
        DB::transaction(function () use ($attemptId, $remoteOrderId, $approvalUrl): void {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if (! in_array($attempt->status, ['creating', 'create_unknown', 'created'], true)
                || ($attempt->paypal_order_id !== null && $attempt->paypal_order_id !== $remoteOrderId)) {
                throw new LogicException('Conflicting PayPal Create result.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'paypal_order_id' => $remoteOrderId, 'status' => 'created',
                'approval_url' => $approvalUrl ?? $attempt->approval_url,
                'diagnostic_code' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Requires a signed/API-confirmed APPROVED status before calling this method. */
    public function markApproved(int $attemptId): void
    {
        $this->transition($attemptId, ['created', 'approved'], 'approved');
    }

    /** One in-flight Capture at a time; unknown outcomes MUST be reconciled via GET. */
    public function claimCapture(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'approved' || $attempt->paypal_order_id === null) {
                return false;
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'status' => 'capturing', 'capture_started_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function markCaptureUnknown(int $attemptId, string $reason = 'api_timeout'): void
    {
        $this->transition($attemptId, ['capturing'], 'capture_unknown', $reason);
    }

    /** A definitive 4xx Create rejection needs a new operator decision, never a blind new attempt. */
    public function markCreateRejected(int $attemptId, string $reason): void
    {
        $this->transition($attemptId, ['creating'], 'create_rejected', $reason);
    }

    /** A confirmed remote capture is evidence to be VERIFIED in iteration 4, not credit. */
    public function markCaptureResponse(int $attemptId, string $captureId, string $captureStatus): void
    {
        self::assertRemoteId($captureId);
        $next = match ($captureStatus) {
            'COMPLETED' => 'capture_completed_unsettled',
            'PENDING' => 'capture_pending',
            'DENIED', 'DECLINED' => 'capture_denied',
            default => throw new InvalidArgumentException('Invalid PayPal capture status.'),
        };
        DB::transaction(function () use ($attemptId, $captureId, $next): void {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'capturing' || ($attempt->paypal_capture_id !== null && $attempt->paypal_capture_id !== $captureId)) {
                throw new LogicException('PayPal capture response conflicts with its attempt.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'status' => $next, 'paypal_capture_id' => $captureId,
                'last_checked_at' => now(), 'diagnostic_code' => null, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Definitive POST rejection; no automatic replay, no Wallet credit. */
    public function markCaptureRejected(int $attemptId, string $reason): void
    {
        $this->transition($attemptId, ['capturing'], 'capture_rejected', $reason);
    }

    /** @param list<string> $allowed */
    private function transition(int $attemptId, array $allowed, string $next, ?string $reason = null): void
    {
        if ($reason !== null && ! preg_match('/\A[a-z][a-z0-9_:-]{0,79}\z/D', $reason)) {
            throw new InvalidArgumentException('Invalid PayPal diagnostic code.');
        }
        DB::transaction(function () use ($attemptId, $allowed, $next, $reason): void {
            $attempt = PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            if (! in_array($attempt->status, $allowed, true)) {
                throw new LogicException('Invalid PayPal attempt transition.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'status' => $next, 'diagnostic_code' => $reason, 'updated_at' => now(),
            ]);
        }, 3);
    }

    private static function assertMerchant(string $merchant): void
    {
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,190}\z/D', $merchant)) {
            throw new InvalidArgumentException('Invalid PayPal merchant identity.');
        }
    }

    private static function assertRemoteId(string $id): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_-]{1,190}\z/D', $id)) {
            throw new InvalidArgumentException('Invalid PayPal remote order identifier.');
        }
    }
}
