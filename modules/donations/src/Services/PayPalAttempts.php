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
    public function reserveLive(DonationPaymentPreparation $preparation, string $merchantIdentity, ?string $expectedPayeeId = null): PayPalAttempt
    {
        self::assertMerchant($merchantIdentity);
        if ($expectedPayeeId === null || ! preg_match('/\A[A-Z0-9]{8,32}\z/D', $expectedPayeeId)) {
            throw new InvalidArgumentException('Missing verified PayPal merchant ID.');
        }

        return DB::transaction(function () use ($preparation, $merchantIdentity, $expectedPayeeId): PayPalAttempt {
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
                    || $existing->expected_payee_id !== $expectedPayeeId
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
                'merchant_identity' => $merchantIdentity, 'expected_payee_id' => $expectedPayeeId, 'status' => 'reserved',
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
            $attempt = $this->lockAttempt($attemptId);
            if ($attempt->status === 'reserved') {
                DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                    'status' => 'creating', 'create_started_at' => now(), 'updated_at' => now(),
                ]);

                return true;
            }
            if (in_array($attempt->status, ['creating', 'create_unknown'], true)
                && ($attempt->create_started_at === null
                    || ! $attempt->create_started_at->greaterThan(now()->subMinutes(350)))) {
                DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                    'status' => 'requires_review', 'diagnostic_code' => 'create_idempotency_expired', 'updated_at' => now(),
                ]);

                return false;
            }
            // The ONLY permitted Create retry uses the SAME persisted request ID
            // inside the default 6h window with a 10-minute safety margin after timeout.
            if ($attempt->status === 'create_unknown' && $attempt->paypal_order_id === null
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
            $attempt = $this->lockAttempt($attemptId);
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
        DB::transaction(function () use ($attemptId): void {
            $attempt = $this->lockAttempt($attemptId);
            if (in_array($attempt->status, ['capturing', 'capture_unknown', 'capture_pending',
                'capture_completed_unsettled', 'capture_denied', 'capture_rejected', 'settled',
                'requires_review', 'sandbox_verified', 'sandbox_pending', 'sandbox_denied'], true)) {
                return; // A parallel verified flow has already advanced beyond approval.
            }
            if (! in_array($attempt->status, ['created', 'approved'], true)) {
                throw new LogicException('Invalid PayPal approval transition.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'status' => 'approved', 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** One in-flight Capture at a time; unknown outcomes MUST be reconciled via GET. */
    public function claimCapture(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = $this->lockAttempt($attemptId);
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
            $attempt = $this->lockAttempt($attemptId);
            if ($attempt->paypal_capture_id !== null && $attempt->paypal_capture_id !== $captureId) {
                throw new LogicException('PayPal capture response conflicts with its attempt.');
            }
            // Sandbox has no corresponding wallet order. Do not give future
            // settlement workers even a superficially eligible live status.
            if ($attempt->purpose === 'admin_test') {
                $next = match ($next) {
                    'capture_completed_unsettled' => 'sandbox_verified',
                    'capture_pending' => 'sandbox_pending',
                    'capture_denied' => 'sandbox_denied',
                };
            }
            if ($attempt->status !== 'capturing') {
                $ranks = ['capture_pending' => 1, 'sandbox_pending' => 1,
                    'capture_denied' => 2, 'sandbox_denied' => 2,
                    'capture_completed_unsettled' => 3, 'sandbox_verified' => 3, 'settled' => 4];
                if ($attempt->paypal_capture_id !== $captureId || ! isset($ranks[$attempt->status])) {
                    throw new LogicException('PayPal capture response conflicts with its attempt.');
                }
                if ($ranks[$attempt->status] >= $ranks[$next]) {
                    return; // Never downgrade an identical capture advanced by a webhook.
                }
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
            $attempt = $this->lockAttempt($attemptId);
            if (! in_array($attempt->status, $allowed, true)) {
                if ($allowed === ['capturing'] && $attempt->paypal_capture_id !== null
                    && in_array($attempt->status, ['capture_pending', 'capture_completed_unsettled',
                        'capture_denied', 'settled', 'sandbox_pending', 'sandbox_verified', 'sandbox_denied'], true)) {
                    return; // A timeout must not erase a concurrently verified response.
                }
                throw new LogicException('Invalid PayPal attempt transition.');
            }
            DB::table('module_donation_paypal_attempts')->where('id', $attemptId)->update([
                'status' => $next, 'diagnostic_code' => $reason, 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Follow the reservation lock order before updating indexed attempt state. */
    private function lockAttempt(int $attemptId): PayPalAttempt
    {
        $candidate = PayPalAttempt::query()->findOrFail($attemptId);
        if ($candidate->order_id !== null) {
            $order = DonationOrder::query()->findOrFail($candidate->order_id);
            User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
            DonationOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        }

        return PayPalAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
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
