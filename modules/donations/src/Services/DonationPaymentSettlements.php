<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Contracts\WalletGateway;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Wallet\WalletSource;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use LogicException;
use RuntimeException;

/**
 * Durable verification + single-effect settlement. The default C3 release has
 * both callback and credit gates closed, and exposes no payment checkout.
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

    public function receive(ProviderCallback $callback): string
    {
        if (strlen($callback->rawBody) < 1 || strlen($callback->rawBody) > 16384) {
            throw new InvalidArgumentException('Invalid signed notification.');
        }
        // Routing hint ONLY. parse_str may silently discard duplicates, which the
        // driver's strict parser ALWAYS rejects before any database mutation.
        parse_str($callback->rawBody, $hint);
        $label = $hint['label'] ?? null;
        if (! is_string($label) || ! preg_match('/\Akaevcms-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $label)) {
            throw new InvalidArgumentException('Invalid payment reference.');
        }
        $preparation = DonationPaymentPreparation::query()->where('provider_label', $label)->first();
        if ($preparation === null || $preparation->provider_code !== 'yoomoney') {
            throw new InvalidArgumentException('Unknown payment reference.');
        }
        $driver = $this->registry->get('yoomoney');
        $credentials = $this->providerSettings->credentialsForDriver('yoomoney');
        $verified = $driver->verifyCallback($callback, $credentials, (string) $preparation->mode);

        return $this->record($preparation, $verified, $callback->rawBody);
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
                $result = $this->receive(new ProviderCallback($body, [], ''));
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

    private function record(DonationPaymentPreparation $initialPreparation, VerifiedPayment $verified, string $rawBody): string
    {
        $hash = hash('sha256', json_encode([
            $verified->orderUuid, $verified->providerPaymentId, $verified->status,
            $verified->currencyCode, $verified->amountMinor, $verified->receivedAmountMinor,
            $verified->testNotification, $verified->environment,
        ], JSON_THROW_ON_ERROR));
        $candidate = DonationOrder::query()->whereKey($initialPreparation->order_id)->first();
        if ($candidate === null) {
            throw new InvalidArgumentException('Unknown payment reference.');
        }

        return DB::transaction(function () use ($initialPreparation, $verified, $hash, $candidate, $rawBody): string {
            // Same lock ordering as core Wallet: user -> order -> provider event -> wallet.
            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $order = DonationOrder::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $preparation = DonationPaymentPreparation::query()->whereKey($initialPreparation->id)->firstOrFail();
            if ((int) $preparation->order_id !== (int) $order->id
                || $preparation->provider_code !== 'yoomoney'
                || $preparation->provider_label !== 'kaevcms-'.$verified->orderUuid
                || $order->order_uuid !== $verified->orderUuid) {
                throw new InvalidArgumentException('Mismatched payment reference.');
            }
            $existing = DB::table('module_donation_payment_receipts')
                ->where('provider_code', 'yoomoney')->where('provider_payment_id', $verified->providerPaymentId)
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
                    'provider_code' => 'yoomoney', 'provider_payment_id' => $verified->providerPaymentId,
                    'currency_code' => $verified->currencyCode,
                    'payer_amount_minor' => $verified->amountMinor,
                    'received_amount_minor' => $verified->receivedAmountMinor,
                    'notification_sha256' => $hash,
                    'encrypted_notification' => Crypt::encryptString($rawBody),
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->auditOrFail('donations.payment_verified', $order, [
                    'provider_code' => 'yoomoney', 'payment_event_id' => $id,
                ]);
            }
            // Provider mode may be changed or disabled after checkout. Changes to
            // settings never enable old test preparations or silently reprice orders.
            $current = DonationProviderSetting::query()->whereKey('yoomoney')->lockForUpdate()->first();
            $reason = null;
            if ($verified->testNotification || $preparation->mode !== 'live' || $verified->environment !== 'live') {
                $reason = 'test_notification';
            } elseif ($verified->status !== 'received_unsettled' || $verified->currencyCode !== $order->currency_code
                || $verified->currencyCode !== $preparation->currency_code
                || (int) $preparation->payer_amount_minor !== (int) $order->total_minor
                || $verified->amountMinor !== (int) $order->total_minor
                || $verified->receivedAmountMinor === null || $verified->receivedAmountMinor < 1
                || $verified->receivedAmountMinor > $verified->amountMinor) {
                $reason = 'amount_or_currency_mismatch';
            } elseif ($current === null || $current->mode !== 'live') {
                $reason = 'provider_mode_changed';
            } elseif ($order->status !== DonationOrder::DRAFT) {
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
                return 'pending'; // Signed and durably stored, but deliberately not settled in C3.
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
            $updated = DB::table('module_donation_orders')->where('id', $order->id)->where('status', DonationOrder::DRAFT)
                ->update([
                    'status' => 'credited', 'provider_code' => 'yoomoney',
                    'provider_payment_id' => $verified->providerPaymentId,
                    'checkout_total_minor' => $verified->amountMinor,
                    'paid_at' => now(), 'credited_at' => now(), 'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Donation order settlement conflict.');
            }
            DB::table('module_donation_order_events')->insert([
                'order_id' => $order->id, 'from_status' => DonationOrder::DRAFT,
                'to_status' => 'credited', 'kind' => 'payment_credited', 'created_at' => now(),
            ]);
            DB::table('module_donation_payment_receipts')->where('id', $id)
                ->update(['status' => 'credited', 'credited_at' => now(), 'updated_at' => now()]);
            $this->auditOrFail('donations.payment_credited', $order, [
                'provider_code' => 'yoomoney', 'payment_event_id' => $id,
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
