<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Contracts\WalletTopUpHistory;
use App\Models\User;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Models\DonationOrder;

/** Read-only integration with the player's common wallet history. */
final class DonationPlayerHistory implements WalletTopUpHistory
{
    public function query(User $user, bool $includeInactive): Builder
    {
        $query = DB::table('module_donation_orders as orders')->where('orders.user_id', $user->getKey())
            ->whereNotExists(function (Builder $query) use ($user): void {
                $query->selectRaw('1')->from('wallet_entries as entries')->join('wallets', 'wallets.id', '=', 'entries.wallet_id')
                    ->where('wallets.user_id', $user->getKey())->where('entries.source', 'donations')->where('entries.direction', 'credit')
                    ->whereRaw(DB::getDriverName() === 'sqlite'
                        ? "entries.source_key = 'donation:' || orders.order_uuid"
                        : "entries.source_key = CONCAT('donation:', orders.order_uuid)");
            });
        if (! $includeInactive) {
            $query->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('orders.status', 'draft')->where('orders.expires_at', '>', now());
                })->orWhereNotIn('orders.status', ['draft', 'cancelled', 'expired'])
                    ->orWhereExists(function (Builder $query): void {
                        $query->selectRaw('1')->from('module_donation_payment_receipts as receipts')
                            ->whereColumn('receipts.order_id', 'orders.id')->whereIn('receipts.status', ['pending', 'review']);
                    });
            });
        }

        return $query->selectRaw("'topup' as kind, orders.id as record_id, orders.created_at as happened_at,
            CASE WHEN orders.status = 'draft' AND orders.expires_at > ? THEN 1 ELSE 0 END as priority", [now()]);
    }

    public function rows(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $result = [];
        $money = app(DonationMoney::class);
        $payments = app(DonationPaymentPreparations::class);
        $checkout = app(DonationPaymentCheckout::class);
        $appearance = app(WalletAppearanceSettings::class);
        $canPay = app(DonationSettings::class)->isVisibleToPlayers()
            && DonationPaymentCheckout::enabled() && DonationPaymentCheckout::allowedForUser($user);
        foreach (DonationOrder::query()->where('user_id', $user->getKey())->whereIn('id', $ids)->get() as $order) {
            $state = $order->displayStatus();
            $waiting = $state === 'draft' && $order->expires_at?->isFuture();
            $row = [
                'key' => 'topup-'.$order->id, 'title' => __('Wallet top-up'),
                'amount' => $appearance->label($order->coins), 'date' => $order->getAttribute('created_at'),
                'detail' => $money->format($order->total_minor, $order->exponent).' '.$order->currency_code,
                'status' => match ($state) {
                    'draft' => __('Waiting for payment'), 'cancelled' => __('Top-up cancelled'),
                    'expired' => __('Payment time expired'), default => __('We are checking your payment'),
                },
                'tone' => $waiting ? 'waiting' : (in_array($state, ['cancelled', 'expired'], true) ? 'muted' : 'waiting'),
                'url' => route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]),
                'expires_at' => $waiting ? $order->expires_at : null, 'pay_url' => null,
                'prepare_url' => null, 'provider' => null, 'cancel_url' => $waiting
                    ? route('modules.donations.orders.cancel', ['orderUuid' => $order->order_uuid]) : null,
                'balance_after' => null,
            ];
            if ($waiting && $canPay) {
                if ($checkout->available($user, $order)) {
                    $row['pay_url'] = route('modules.donations.orders.checkout', ['orderUuid' => $order->order_uuid]);
                } elseif ($payments->forOrder($order) === null
                    && ! DB::table('module_donation_payment_receipts')->where('order_id', $order->id)->exists()) {
                    $providers = array_values(array_filter($payments->available($order->currency_code),
                        static fn (array $provider): bool => $provider['mode'] === 'live'));
                    if (count($providers) === 1) {
                        $row['prepare_url'] = route('modules.donations.orders.prepare', ['orderUuid' => $order->order_uuid]);
                        $row['provider'] = $providers[0]['code'];
                    }
                }
            }
            $result[(int) $order->id] = $row;
        }

        return $result;
    }

    public function received(User $user, Collection $entries): array
    {
        $keys = $entries->where('source', 'donations')->where('direction', 'credit')->pluck('source_key')->all();
        $uuids = array_map(static fn (string $key): string => substr($key, strlen('donation:')),
            array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'donation:'))));
        if ($uuids === []) {
            return [];
        }
        $orders = DonationOrder::query()->where('user_id', $user->getKey())->whereIn('order_uuid', $uuids)->get()->keyBy('order_uuid');
        $money = app(DonationMoney::class);
        $result = [];
        foreach ($entries as $entry) {
            if ($entry->source !== 'donations' || $entry->direction !== 'credit' || ! str_starts_with($entry->source_key, 'donation:')) {
                continue;
            }
            $order = $orders->get(substr($entry->source_key, strlen('donation:')));
            if ($order !== null) {
                $result[$entry->id] = ['title' => __('Coins purchased'), 'status' => __('Coins added'),
                    'detail' => $money->format($order->total_minor, $order->exponent).' '.$order->currency_code,
                    'url' => route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid])];
            }
        }

        return $result;
    }
}
