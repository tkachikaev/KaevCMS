<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;
use RuntimeException;

/** Durable webhook inbox; each message must be signed for the matched configured environment. */
final class PayPalWebhooks
{
    private const EVENTS = [
        'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
        'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED',
    ];

    public function __construct(
        private readonly PayPalAdminSettings $settings,
        private readonly PayPalWebhookVerifier $verifier,
        private readonly PayPalOrderOperations $operations,
        private readonly PayPalVerifiedPayments $verified,
    ) {}

    public function receive(string $raw, array $headers): void
    {
        if (strlen($raw) > 65536 || ! PayPalWebhookVerifier::isJsonObject($raw)) {
            throw new InvalidArgumentException('Invalid PayPal webhook body.');
        }
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $id = $event['id'] ?? null;
        $type = $event['event_type'] ?? null;
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $id)
            || ! is_string($type) || ! in_array($type, self::EVENTS, true)
            || ! is_array($event['resource'] ?? null)) {
            throw new InvalidArgumentException('Unknown PayPal event.');
        }
        $resource = $event['resource'];
        $remoteOrderId = str_starts_with($type, 'CHECKOUT.') ? ($resource['id'] ?? null)
            : ($resource['supplementary_data']['related_ids']['order_id'] ?? null);
        $captureId = str_starts_with($type, 'PAYMENT.CAPTURE.') ? ($resource['id'] ?? null) : null;
        if (str_starts_with($type, 'PAYMENT.CAPTURE.')
            && (! is_string($captureId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId))) {
            throw new InvalidArgumentException('Missing PayPal capture ID.');
        }
        if ($type === 'CUSTOMER.DISPUTE.CREATED') {
            // Handle dispute correlation in iteration 5; persist only when a known capture is provided.
            $captureId = $resource['disputed_transactions'][0]['seller_transaction_id'] ?? null;
        }
        $candidates = collect();
        if (is_string($remoteOrderId) && preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $remoteOrderId)) {
            $candidates = PayPalAttempt::query()->where('paypal_order_id', $remoteOrderId)->limit(3)->get();
        } elseif (is_string($captureId) && preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId)) {
            $candidates = PayPalAttempt::query()->where('paypal_capture_id', $captureId)->limit(3)->get();
        }
        if ($candidates->count() !== 1) {
            throw new InvalidArgumentException('PayPal webhook has no unambiguous known order.');
        }
        /** @var PayPalAttempt $attempt */
        $attempt = $candidates->first();
        if ($captureId !== null && $attempt->paypal_capture_id !== null
            && $attempt->paypal_capture_id !== $captureId
            && str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            throw new InvalidArgumentException('PayPal capture does not match the known attempt.');
        }
        $credentials = $this->settings->credentials((string) $attempt->environment);
        if ($attempt->merchant_identity !== $credentials->merchantIdentity()) {
            // Credentials were rotated. Do not verify an old merchant using a new one.
            throw new RuntimeException('PayPal historical credentials unavailable.');
        }
        $webhookId = $this->settings->webhookId((string) $attempt->environment);
        if ($webhookId === null || ! $this->verifier->verified($raw, $headers, $webhookId, $credentials)) {
            throw new InvalidArgumentException('Invalid PayPal webhook signature.');
        }
        $eventId = DB::transaction(function () use ($attempt, $id, $type, $raw): int {
            $existing = DB::table('module_donation_paypal_webhook_events')
                ->where('environment', $attempt->environment)->where('event_id', $id)->lockForUpdate()->first();
            $hash = hash('sha256', $raw);
            if ($existing !== null) {
                if ((int) $existing->attempt_id !== (int) $attempt->id
                    || ! hash_equals((string) $existing->payload_sha256, $hash)
                    || $existing->event_type !== $type) {
                    throw new LogicException('Conflicting PayPal webhook event ID.');
                }
                return (int) $existing->id;
            }
            return DB::table('module_donation_paypal_webhook_events')->insertGetId([
                'attempt_id' => $attempt->id, 'environment' => $attempt->environment,
                'event_id' => $id, 'event_type' => $type, 'payload_sha256' => $hash,
                'encrypted_raw_event' => Crypt::encryptString($raw),
                'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
        $this->process($eventId, $attempt, $type, is_string($captureId) ? $captureId : null);
    }

    private function process(int $eventId, PayPalAttempt $attempt, string $type, ?string $captureId): void
    {
        $record = DB::table('module_donation_paypal_webhook_events')->where('id', $eventId)->firstOrFail();
        if ($record->status === 'processed') {
            return;
        }
        if ($attempt->environment === 'live' && $attempt->purpose === 'player') {
            if ($type === 'CHECKOUT.ORDER.APPROVED') {
                $current = PayPalAttempt::query()->findOrFail($attempt->id);
                if (in_array($current->status, ['created', 'approved'], true)) {
                    $this->operations->capture((int) $attempt->id, $this->settings->credentials('live'));
                }
                // Even if a concurrent return/capture took over, only read-only remote proof settles.
                $this->verified->reconcile((int) $attempt->id);
            } elseif ($type === 'PAYMENT.CAPTURE.COMPLETED') {
                $result = $this->verified->reconcile((int) $attempt->id, $captureId);
                if ($result === null) {
                    throw new RuntimeException('PayPal capture not yet remotely confirmed.');
                }
            } elseif ($type === 'PAYMENT.CAPTURE.PENDING' || $type === 'PAYMENT.CAPTURE.DENIED') {
                // Signed status only; no credits and never override a completed settlement.
                DB::transaction(function () use ($attempt, $captureId, $type): void {
                    $locked = PayPalAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === 'settled') {
                        return;
                    }
                    if ($locked->paypal_capture_id !== null && $locked->paypal_capture_id !== $captureId) {
                        throw new LogicException('PayPal capture status belongs to another payment.');
                    }
                    DB::table('module_donation_paypal_attempts')->where('id', $attempt->id)->update([
                        'paypal_capture_id' => $captureId,
                        'status' => $type === 'PAYMENT.CAPTURE.PENDING' ? 'capture_pending' : 'capture_denied',
                        'updated_at' => now(),
                    ]);
                }, 3);
            } elseif ($type === 'CHECKOUT.PAYMENT-APPROVAL.REVERSED') {
                DB::table('module_donation_paypal_attempts')->where('id', $attempt->id)
                    ->whereNotIn('status', ['settled', 'capture_completed_unsettled'])
                    ->update(['status' => 'approval_reversed', 'updated_at' => now()]);
            }
            // Refund/dispute events are recorded as verified evidence only.
            // They do not debit Wallet or change the credited order (iteration 5 adds UI).
        }
        DB::table('module_donation_paypal_webhook_events')->where('id', $eventId)
            ->update(['status' => 'processed', 'updated_at' => now()]);
    }
}
