<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Contracts\WalletGateway;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletSource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Data\VerifiedReceiptEnvelope;
use KaevCMS\Modules\Donations\Exceptions\InvalidPaymentSignature;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;
use RuntimeException;

/**
 * Durable verification + single-effect settlement. Trusted callback/credit
 * guards are independent of the database switch that closes new checkout.
 * No HTTP parameter is an authority for recipient, price, wallet or source.
 */
final class DonationPaymentSettlements
{
    public function __construct(
        private readonly DonationProviderRegistry $registry,
        private readonly DonationProviderSettings $providerSettings,
        private readonly WalletGateway $wallets,
        private readonly AuditLogger $audit,
    ) {}

    public function receive(ProviderCallback $callback, string $providerCode = 'yoomoney'): string
    {
        if (strlen($callback->rawBody) < 1 || strlen($callback->rawBody) > 16384) {
            throw new InvalidArgumentException('Invalid signed notification.');
        }
        // The endpoint supplies the installed provider code. Never infer the
        // driver class from a request parameter or unverified callback field.
        $driver = $this->registry->get($providerCode);
        if (! $driver instanceof RoutablePaymentProvider) {
            throw new InvalidArgumentException('Provider has no reviewed payment callback contract.');
        }
        $reference = $driver->referenceFromCallback($callback); // UNTRUSTED routing hint
        $preparation = DonationPaymentPreparation::query()->where('provider_code', $providerCode)
            ->where('provider_label', $reference)->first();
        if ($preparation === null) {
            throw new InvalidArgumentException('Unknown payment reference.');
        }
        $verified = null;
        // One current key, then only bounded historical keys eligible for this
        // preparation. Do not fall back after malformed payload/amount/label.
        foreach ($this->providerSettings->notificationVerificationCredentials($preparation) as $credentials) {
            try {
                $verified = $driver->verifyCallback($callback, $credentials, (string) $preparation->mode);
                break;
            } catch (InvalidPaymentSignature) {
                // Only invalid signatures may try the bounded retired keys.
                // Invalid amounts, malformed payloads and references must fail closed.
            }
        }
        if ($verified === null) {
            throw new InvalidPaymentSignature('Invalid payment signature.');
        }

        return $this->record($preparation, $verified, $callback->rawBody);
    }

    /** Called ONLY with a verified PayPal API capture, never with browser or unverified webhook data.
     * The verifying service must first confirm original order, payee, amount and capture ID.
     */
    public function recordVerifiedPayPal(PayPalAttempt $attempt, VerifiedPayment $payment): string
    {
        if ($attempt->purpose !== 'player' || $attempt->environment !== 'live'
            || $attempt->order_id === null || $attempt->preparation_id === null
            || $attempt->paypal_order_id === null || $attempt->paypal_capture_id === null
            || $attempt->paypal_capture_id !== $payment->providerPaymentId
            || $attempt->currency_code !== $payment->currencyCode
            || (int) $attempt->gross_amount_minor !== $payment->amountMinor
            || $payment->status !== 'received_unsettled' || $payment->environment !== 'live'
            || $payment->testNotification) {
            throw new InvalidArgumentException('PayPal receipt does not match verified attempt.');
        }
        $preparation = DonationPaymentPreparation::query()->whereKey($attempt->preparation_id)->firstOrFail();
        if ($preparation->provider_code !== 'paypal' || $preparation->mode !== 'live'
            || (int) $preparation->order_id !== (int) $attempt->order_id) {
            throw new InvalidArgumentException('PayPal receipt has no live preparation.');
        }
        // Authentication is via the verified remote order+capture, not raw event fields.
        $evidence = 'paypal-api-verified:'.$attempt->paypal_order_id.':'.$attempt->paypal_capture_id;

        return $this->record($preparation, $payment, $evidence);
    }

    /** Re-verifies encrypted originals before activating any retained evidence. */
    public function retryPending(int $limit = 50): int
    {
        if (config('donation-payments.credits_enabled') !== true || config('wallet.enabled_sources.donations') !== true) {
            return 0;
        }
        $ids = DB::table('module_donation_payment_receipts')
            ->where('status', 'pending')->orderBy('id')->limit(max(1, min(200, $limit)))->pluck('id');
        $completed = 0;
        foreach ($ids as $id) {
            $receipt = DB::table('module_donation_payment_receipts')->where('id', $id)->first();
            if ($receipt === null || $receipt->status !== 'pending') {
                continue;
            }
            try {
                $body = Crypt::decryptString($receipt->encrypted_notification);
                $envelope = VerifiedReceiptEnvelope::restore($body);
                if ($envelope === null) {
                    // Legacy C3/C4 receipts only carried the signed original. They
                    // still need a currently eligible provider key to be verified.
                    if ($receipt->provider_code !== 'yoomoney') {
                        throw new InvalidArgumentException('Unsupported legacy receipt provider.');
                    }
                    $result = $this->receive(new ProviderCallback($body, [], ''), 'yoomoney');
                } else {
                    // The original callback was already verified before we stored
                    // this authenticated Laravel-Crypt envelope. Rotating the
                    // provider's HMAC key cannot erase that historical evidence.
                    // Bind it to the exact existing receipt; never trust an
                    // unsigned provider-history candidate for this path.
                    $verified = $envelope['verified'];
                    if ($receipt->provider_code !== $envelope['providerCode']
                        || $receipt->provider_payment_id !== $verified->providerPaymentId
                        || $receipt->currency_code !== $verified->currencyCode
                        || (int) $receipt->payer_amount_minor !== $verified->amountMinor
                        || ($receipt->received_amount_minor === null ? null : (int) $receipt->received_amount_minor) !== $verified->receivedAmountMinor
                        || ($receipt->received_currency_code ?? null) !== $verified->receivedCurrencyCode
                        || ($receipt->fee_amount_minor === null ? null : (int) $receipt->fee_amount_minor) !== $verified->feeAmountMinor
                        || ($receipt->fee_currency_code ?? null) !== $verified->feeCurrencyCode
                        || ! hash_equals((string) $receipt->notification_sha256,
                            VerifiedReceiptEnvelope::fingerprint($verified))) {
                        throw new InvalidArgumentException('Stored payment evidence mismatch.');
                    }
                    $preparation = DonationPaymentPreparation::query()->whereKey($receipt->preparation_id)->firstOrFail();
                    $driver = $this->registry->get((string) $receipt->provider_code);
                    if (! $driver instanceof RoutablePaymentProvider
                        || (int) $preparation->order_id !== (int) $receipt->order_id
                        || $preparation->provider_code !== $receipt->provider_code
                        || $preparation->provider_label !== $driver->referenceForOrder($verified->orderUuid)) {
                        throw new InvalidArgumentException('Stored payment reference mismatch.');
                    }
                    $result = $this->record($preparation, $verified, $envelope['rawBody']);
                }
                if ($result === 'credited') {
                    $completed++;
                }
            } catch (\Throwable) {
                // Preserve evidence, report unprocessed count; never trust a stale
                // receipt without rechecking its authenticated original.
                continue;
            }
        }

        return $completed;
    }

    /**
     * Operator-initiated recovery when SCI callbacks were lost. This must only
     * be called by an authorized administrative action, never from a public
     * user-supplied order UUID. All financial data comes from the locked,
     * immutable CMS order and from FreeKassa's authenticated merchant API.
     * An API outage or ambiguous response cannot create payment evidence.
     */
    public function recoverFreeKassaByApi(string $orderUuid): string
    {
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderUuid)) {
            throw new InvalidArgumentException('Invalid recovery order UUID.');
        }
        $order = DonationOrder::query()->where('order_uuid', $orderUuid)->firstOrFail();
        $preparation = DonationPaymentPreparation::query()
            ->where('order_id', $order->getKey())->where('provider_code', 'freekassa')->firstOrFail();
        if ($preparation->mode !== 'live' || $preparation->provider_label !== $orderUuid
            || $preparation->currency_code !== $order->currency_code
            || (int) $preparation->payer_amount_minor !== (int) $order->total_minor
            || (int) $order->exponent !== 2 || (int) $order->total_minor < 1
            || $order->status === 'credited') {
            throw new LogicException('Order is not eligible for FreeKassa API recovery.');
        }
        // This may succeed after the provider is disabled for NEW purchases.
        // Use currently valid encrypted API credentials and fail closed if gone.
        $credentials = $this->providerSettings->credentialsForDriver('freekassa');
        if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', $credentials['merchant_id'] ?? '')
            || ! is_string($credentials['api_key'] ?? null) || strlen($credentials['api_key']) < 8) {
            throw new LogicException('FreeKassa merchant API credentials are unavailable.');
        }
        $canonicalId = app(FreeKassaOrderApi::class)->assertPaid(
            $credentials['merchant_id'], $credentials['api_key'], $orderUuid, null,
            (int) $order->total_minor, (string) $order->currency_code,
        );
        $verified = new VerifiedPayment(
            orderUuid: $orderUuid,
            providerPaymentId: $canonicalId,
            status: 'received_unsettled',
            currencyCode: (string) $order->currency_code,
            amountMinor: (int) $order->total_minor,
            receivedAmountMinor: (int) $order->total_minor,
            testNotification: false,
            environment: 'live',
        );

        // This is explicitly API-confirmed evidence, NOT a forged SCI callback.
        // Its encrypted v2 receipt can still be replayed by retryPending().
        return $this->record($preparation, $verified, 'freekassa-api-recovery:'.$orderUuid.':'.$canonicalId);
    }

    private function record(DonationPaymentPreparation $initialPreparation, VerifiedPayment $verified, string $rawBody): string
    {
        $providerCode = (string) $initialPreparation->provider_code;
        $driver = $this->registry->get($providerCode);
        if (! $driver instanceof RoutablePaymentProvider) {
            throw new InvalidArgumentException('Unreviewed payment provider.');
        }
        $hash = VerifiedReceiptEnvelope::fingerprint($verified);
        $candidate = DonationOrder::query()->whereKey($initialPreparation->order_id)->first();
        if ($candidate === null) {
            throw new InvalidArgumentException('Unknown payment reference.');
        }

        return DB::transaction(function () use ($initialPreparation, $verified, $hash, $candidate, $rawBody, $providerCode, $driver): string {
            // Same lock ordering as core Wallet: user -> order -> provider event -> wallet.
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $order = DonationOrder::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $preparation = DonationPaymentPreparation::query()->whereKey($initialPreparation->id)->firstOrFail();
            if ((int) $preparation->order_id !== (int) $order->id
                || $preparation->provider_code !== $providerCode
                || $preparation->provider_label !== $driver->referenceForOrder($verified->orderUuid)
                || $order->order_uuid !== $verified->orderUuid) {
                throw new InvalidArgumentException('Mismatched payment reference.');
            }
            $existing = DB::table('module_donation_payment_receipts')
                ->where('provider_code', $providerCode)->where('provider_payment_id', $verified->providerPaymentId)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->order_id !== (int) $order->id || ! hash_equals($existing->notification_sha256, $hash)) {
                    // A provider operation ID cannot be reassigned to another order or amount.
                    throw new LogicException('Conflicting signed provider operation.');
                }
                if ($existing->status !== 'pending') {
                    return $existing->status;
                }
                $id = (int) $existing->id;
            } else {
                $id = DB::table('module_donation_payment_receipts')->insertGetId([
                    'order_id' => $order->id, 'preparation_id' => $preparation->id,
                    'provider_code' => $providerCode, 'provider_payment_id' => $verified->providerPaymentId,
                    'currency_code' => $verified->currencyCode,
                    'payer_amount_minor' => $verified->amountMinor,
                    'received_amount_minor' => $verified->receivedAmountMinor,
                    'received_currency_code' => $verified->receivedCurrencyCode,
                    'fee_amount_minor' => $verified->feeAmountMinor,
                    'fee_currency_code' => $verified->feeCurrencyCode,
                    'notification_sha256' => $hash,
                    'encrypted_notification' => Crypt::encryptString(VerifiedReceiptEnvelope::seal($verified, $rawBody, $providerCode)),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->auditOrFail('donations.payment_verified', $order, [
                    'provider_code' => $providerCode, 'payment_event_id' => $id,
                ]);
            }
            // Honor the immutable preparation snapshot: disabling a provider or
            // switching test/live later closes NEW checkouts, not signed receipts
            // already attached to an order. Credential rotation is a separate
            // concern and must retain the ability to verify prior signatures.
            $reason = null;
            if ($verified->testNotification || $preparation->mode !== 'live' || $verified->environment !== 'live') {
                $reason = 'test_notification';
            } elseif ($verified->status !== 'received_unsettled' || $verified->currencyCode !== $order->currency_code
                || $verified->currencyCode !== $preparation->currency_code
                || (int) $preparation->payer_amount_minor !== (int) $order->total_minor
                || $verified->amountMinor !== (int) $order->total_minor
                || ($verified->receivedAmountMinor !== null && $verified->receivedAmountMinor < 0)
                || ($verified->receivedCurrencyCode !== null && ($verified->receivedAmountMinor === null
                    || ! preg_match('/\A[A-Z]{3}\z/D', $verified->receivedCurrencyCode)))
                || ($verified->receivedAmountMinor !== null
                    && ($verified->receivedCurrencyCode === null || $verified->receivedCurrencyCode === $verified->currencyCode)
                    && $verified->receivedAmountMinor > $verified->amountMinor)
                || ($verified->feeAmountMinor === null) !== ($verified->feeCurrencyCode === null)
                || ($verified->feeAmountMinor !== null && $verified->feeAmountMinor < 0)
                || ($verified->feeCurrencyCode !== null && ! preg_match('/\A[A-Z]{3}\z/D', $verified->feeCurrencyCode))) {
                $reason = 'amount_or_currency_mismatch';
            } elseif (! in_array($order->status, [
                DonationOrder::DRAFT, DonationOrder::EXPIRED, DonationOrder::CANCELLED,
            ], true)) {
                // A second genuine transfer cannot credit an already settled order.
                $reason = 'order_not_payable';
            }
            if ($reason !== null) {
                $status = $reason === 'test_notification' ? 'test' : 'review';
                DB::table('module_donation_payment_receipts')->where('id', $id)
                    ->update(['status' => $status, 'review_reason' => $reason, 'updated_at' => now()]);
                $this->auditOrFail('donations.payment_'.$status, $order, ['payment_event_id' => $id, 'reason' => $reason]);

                return $status;
            }
            if (config('donation-payments.credits_enabled') !== true || config('wallet.enabled_sources.donations') !== true) {
                return 'pending'; // Preserve signed evidence while a trusted credit guard is closed.
            }
            // Unique per order AND wallet source-key: DB constraints plus Wallet's own
            // idempotency make the effect exactly once under concurrent deliveries.
            $entry = $this->wallets->creditFromModule(
                WalletSource::Donations, $user, (int) $order->coins,
                'donation:'.$order->order_uuid, 'Donation order '.$order->order_uuid,
            );
            DB::table('module_donation_payment_settlements')->insert([
                'order_id' => $order->id, 'receipt_id' => $id,
                'wallet_entry_id' => $entry->id, 'created_at' => now(),
            ]);
            $previousStatus = $order->status;
            $updated = DB::table('module_donation_orders')->where('id', $order->id)
                ->whereIn('status', [DonationOrder::DRAFT, DonationOrder::EXPIRED, DonationOrder::CANCELLED])
                ->update([
                    'status' => 'credited', 'provider_code' => $providerCode,
                    'provider_payment_id' => $verified->providerPaymentId,
                    'checkout_total_minor' => $verified->amountMinor,
                    'paid_at' => now(), 'credited_at' => now(), 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Donation order settlement conflict.');
            }
            DB::table('module_donation_order_events')->insert([
                'order_id' => $order->id, 'from_status' => $previousStatus,
                'to_status' => 'credited', 'kind' => 'payment_credited', 'created_at' => now(),
            ]);
            DB::table('module_donation_payment_receipts')->where('id', $id)
                ->update(['status' => 'credited', 'credited_at' => now(), 'updated_at' => now()]);
            if ($providerCode === 'paypal') {
                // Keep the derived attempt state in the same commit as Wallet,
                // including deferred receipt replay after the credit guard reopens.
                $bound = DB::table('module_donation_paypal_attempts')
                    ->where('preparation_id', $preparation->id)->where('order_id', $order->id)
                    ->where('purpose', 'player')->where('environment', 'live')
                    ->where('paypal_capture_id', $verified->providerPaymentId)
                    ->update(['status' => 'settled', 'updated_at' => now()]);
                if ($bound !== 1) {
                    throw new RuntimeException('PayPal settlement has no matching player attempt.');
                }
            }
            $this->auditOrFail('donations.payment_credited', $order, [
                'provider_code' => $providerCode, 'payment_event_id' => $id,
                'wallet_entry_id' => $entry->id,
            ]);

            return 'credited';
        }, 3);
    }

    /** @param array<string,scalar> $details */
    private function auditOrFail(string $action, DonationOrder $order, array $details): void
    {
        if ($this->audit->system('module', $action, 'donation-order', [
            'order_uuid' => $order->order_uuid,
            ...$details,
        ]) === null) {
            throw new RuntimeException('Unable to record verified Donations event.');
        }
    }
}
