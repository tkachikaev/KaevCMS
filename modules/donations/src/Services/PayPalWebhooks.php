<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Services\Notifications\AdminNotificationCenter;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationSeverity;
use App\Support\Notifications\AdminNotificationType;
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
        private readonly AdminNotificationCenter $notifications,
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
        $informational = in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED'], true);
        $remoteOrderId = str_starts_with($type, 'CHECKOUT.') ? ($resource['id'] ?? null)
            : ($resource['supplementary_data']['related_ids']['order_id'] ?? null);
        $captureId = null;
        if (str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            // A refund resource may use its own refund ID; never treat it as the capture ID.
            $upId = $informational ? $this->captureFromUpLink($resource) : null;
            $captureId = $resource['supplementary_data']['related_ids']['capture_id']
                ?? $resource['capture_id'] ?? $upId
                ?? ($type === 'PAYMENT.CAPTURE.REFUNDED' ? null : ($resource['id'] ?? null));
            if ($upId !== null && $captureId !== $upId) {
                throw new InvalidArgumentException('PayPal capture links are contradictory.');
            }
        } elseif ($type === 'CUSTOMER.DISPUTE.CREATED') {
            $transactions = $resource['disputed_transactions'] ?? [];
            if (! is_array($transactions) || count($transactions) !== 1) {
                throw new InvalidArgumentException('PayPal dispute has no unique transaction.');
            }
            $captureId = $transactions[0]['seller_transaction_id'] ?? null;
        }
        if ($type === 'PAYMENT.CAPTURE.REFUNDED' && $captureId === null
            && is_string($resource['id'] ?? null)
            && PayPalAttempt::query()->where('paypal_capture_id', $resource['id'])->exists()) {
            // Some capture-refund event formats identify the original capture directly.
            // Only allow this form when the ID is ALREADY stored as a known capture.
            $captureId = $resource['id'];
        }
        if ($informational && (! is_string($captureId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId))) {
            throw new InvalidArgumentException('PayPal informational event has no original capture ID.');
        }
        if (str_starts_with($type, 'PAYMENT.CAPTURE.') && ! $informational
            && (! is_string($captureId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId))) {
            throw new InvalidArgumentException('Missing PayPal capture ID.');
        }
        $resourceId = $resource['dispute_id'] ?? $resource['id'] ?? null;
        if (! is_string($resourceId) || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $resourceId)) {
            $resourceId = null;
        }
        // PayPal event timestamps are untrusted until the signature is checked below.
        $occurredAt = $event['create_time'] ?? null;
        if (! is_string($occurredAt) || strlen($occurredAt) > 40
            || strtotime($occurredAt) === false) {
            $occurredAt = null;
        }
        $candidates = collect();
        if (is_string($remoteOrderId) && preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $remoteOrderId)) {
            $candidates = PayPalAttempt::query()->where('paypal_order_id', $remoteOrderId)->limit(3)->get();
        } elseif (is_string($captureId) && preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $captureId)) {
            $candidates = PayPalAttempt::query()->where('paypal_capture_id', $captureId)->limit(3)->get();
        }
        if ($informational && is_string($captureId)) {
            // Correlate financial warnings with the EXACT already-settled capture,
            // not merely an incoming order_id or another merchant's refund ID.
            $candidates = PayPalAttempt::query()->where('paypal_capture_id', $captureId)->limit(3)->get();
        }
        if ($candidates->count() !== 1) {
            throw new InvalidArgumentException('PayPal webhook has no unambiguous known order.');
        }
        /** @var PayPalAttempt $attempt */
        $attempt = $candidates->first();
        if ($informational && is_string($remoteOrderId)
            && $remoteOrderId !== $attempt->paypal_order_id) {
            throw new InvalidArgumentException('PayPal notice order and capture disagree.');
        }
        if ($informational && str_starts_with($type, 'PAYMENT.CAPTURE.')
            && $this->captureFromUpLink($resource) !== null) {
            // An event signed for Sandbox cannot reference a Live API host or vice versa.
            $href = collect($resource['links'] ?? [])->first(static fn ($link): bool => is_array($link)
                && ($link['rel'] ?? null) === 'up' && str_contains((string) ($link['href'] ?? ''), '/v2/payments/captures/'))['href'] ?? '';
            $host = is_string($href) ? parse_url($href, PHP_URL_HOST) : null;
            $isSandboxHost = in_array($host, ['api-m.sandbox.paypal.com', 'api.sandbox.paypal.com'], true);
            if ($isSandboxHost !== ($attempt->environment === 'sandbox')) {
                throw new InvalidArgumentException('PayPal capture link environment mismatch.');
            }
        }
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
        $eventId = DB::transaction(function () use ($attempt, $id, $type, $raw, $resourceId, $occurredAt): int {
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
                'resource_id' => $resourceId, 'event_occurred_at' => $occurredAt === null ? null : date('Y-m-d H:i:s', strtotime($occurredAt)),
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
            if (in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED'], true)) {
                // A signed notice alone is not a reason to mark an arbitrary order.
                // The capture must already have passed the shared Live settlement and
                // remain bound to the original paid receipt. No debit or penalty.
                $current = PayPalAttempt::query()->findOrFail($attempt->id);
                $paid = $current->order_id !== null
                    && DB::table('module_donation_orders')->where('id', $current->order_id)
                        ->where('status', 'credited')->exists()
                    && DB::table('module_donation_payment_receipts')
                        ->where('order_id', $current->order_id)->where('provider_code', 'paypal')
                        ->where('provider_payment_id', $captureId)->where('status', 'credited')->exists();
                if ($current->status !== 'settled' || $current->paypal_capture_id !== $captureId || ! $paid) {
                    throw new RuntimeException('PayPal informational event is not bound to a settled capture.');
                }
            }
        }
        DB::table('module_donation_paypal_webhook_events')->where('id', $eventId)
            ->update(['status' => 'processed', 'updated_at' => now()]);
        if ($attempt->environment === 'live' && $attempt->purpose === 'player'
            && $attempt->order_id !== null
            && in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED'], true)) {
            $orderUuid = DB::table('module_donation_orders')->where('id', $attempt->order_id)->value('order_uuid');
            if (is_string($orderUuid) && $orderUuid !== '') {
                $key = match ($type) {
                    'PAYMENT.CAPTURE.REFUNDED' => 'paypal_notice_refunded',
                    'PAYMENT.CAPTURE.REVERSED' => 'paypal_notice_reversed',
                    default => 'paypal_notice_dispute',
                };
                $this->notifications->notifyOnce(new AdminNotificationData(
                    type: AdminNotificationType::DonationPayPalNotice,
                    severity: AdminNotificationSeverity::Warning,
                    titleKey: 'module-donations::messages.'.$key,
                    messageKey: 'module-donations::messages.paypal_notice_notification_help',
                    parameters: ['order' => $orderUuid],
                    routeName: 'admin.module-pages.donations.orders.show',
                    routeParameters: ['orderUuid' => $orderUuid],
                ), 'donations:paypal:notice:'.$attempt->environment.':'.$type.':'.($record->resource_id ?: $captureId),
                    recipientFilter: static fn (\App\Models\Admin $admin): bool => in_array(
                        $admin->role, [AdminRole::Owner, AdminRole::Administrator], true,
                    ));
            }
        }
    }

    /** The refund `up` relation identifies the original Capture, not the refund ID.
     * Only parse a trusted PayPal URL as an ID; never request arbitrary HATEOAS URLs. */
    private function captureFromUpLink(array $resource): ?string
    {
        $links = $resource['links'] ?? [];
        if (! is_array($links) || count($links) > 50) {
            throw new InvalidArgumentException('Malformed PayPal capture links.');
        }
        $found = null;
        foreach ($links as $link) {
            if (! is_array($link) || ($link['rel'] ?? null) !== 'up') {
                continue;
            }
            $href = $link['href'] ?? null;
            if (! is_string($href) || strlen($href) > 2048) {
                throw new InvalidArgumentException('Invalid PayPal capture reference.');
            }
            $parts = parse_url($href);
            if (is_array($parts) && preg_match('#\A/v2/checkout/orders/[A-Za-z0-9_-]{1,190}\z#D', $parts['path'] ?? '') === 1) {
                // A completed capture uses `up` to identify the order, not a capture.
                continue;
            }
            if (($link['method'] ?? null) !== 'GET') {
                throw new InvalidArgumentException('Invalid PayPal capture reference.');
            }
            if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
                || ! in_array($parts['host'] ?? '', [
                    'api-m.paypal.com', 'api.paypal.com', 'api-m.sandbox.paypal.com', 'api.sandbox.paypal.com',
                ], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
                || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
                || preg_match('#\A/v2/payments/captures/([A-Za-z0-9_-]{1,190})\z#D', $parts['path'] ?? '', $match) !== 1) {
                throw new InvalidArgumentException('Unsafe PayPal capture up-link.');
            }
            if ($found !== null && $found !== $match[1]) {
                throw new InvalidArgumentException('Ambiguous PayPal capture up-link.');
            }
            $found = $match[1];
        }

        return $found;
    }

}
