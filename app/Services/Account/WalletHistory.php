<?php

namespace App\Services\Account;

use App\Contracts\WalletTopUpHistory;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** A bounded, user-owned timeline; Wallet's immutable ledger remains authoritative. */
final class WalletHistory
{
    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function page(User $user, bool $includeInactive): LengthAwarePaginator
    {
        $query = DB::table('wallet_entries')->join('wallets', 'wallets.id', '=', 'wallet_entries.wallet_id')
            ->where('wallets.user_id', $user->getKey())
            ->selectRaw("'wallet' as kind, wallet_entries.id as record_id, occurred_at as happened_at, 0 as priority");
        $topUps = app()->bound(WalletTopUpHistory::class) ? app(WalletTopUpHistory::class) : null;
        if ($topUps !== null) {
            $query->unionAll($topUps->query($user, $includeInactive));
        }
        $page = DB::query()->fromSub($query, 'history')->orderByDesc('priority')->orderByDesc('happened_at')
            ->orderByDesc('kind')->orderByDesc('record_id')->paginate(20)->withQueryString();
        $references = $page->getCollection();
        $walletIds = $references->where('kind', 'wallet')->pluck('record_id')->map(fn ($id): int => (int) $id)->all();
        $orderIds = $references->where('kind', 'topup')->pluck('record_id')->map(fn ($id): int => (int) $id)->all();
        $entries = WalletEntry::query()->whereIn('id', $walletIds)
            ->whereHas('wallet', fn ($query) => $query->where('user_id', $user->getKey()))->get()->keyBy('id');
        $orders = $topUps?->rows($user, $orderIds) ?? [];
        $received = $topUps?->received($user, $entries) ?? [];
        $appearance = app(WalletAppearanceSettings::class);
        $rows = [];
        foreach ($references as $reference) {
            $id = (int) $reference->record_id;
            if ($reference->kind === 'topup') {
                if (isset($orders[$id])) {
                    $rows[] = $orders[$id];
                }

                continue;
            }
            $entry = $entries->get($id);
            if ($entry === null) {
                continue;
            }
            $credit = $entry->direction === 'credit';
            $rows[] = array_replace([
                'key' => 'wallet-'.$id, 'title' => $entry->source === 'donation_exchange'
                    ? __('Transfer to game') : ($credit ? __('Coins received') : __('Coins spent')),
                'amount' => ($credit ? '+' : '−').$appearance->label($entry->amount),
                'date' => $entry->occurred_at, 'detail' => null, 'status' => null,
                'tone' => $credit ? 'success' : 'neutral', 'url' => null,
                'expires_at' => null, 'pay_url' => null, 'prepare_url' => null, 'provider' => null, 'cancel_url' => null,
                'balance_after' => __('Balance after: :amount', ['amount' => number_format($entry->balance_after, 0, '.', ' ')]),
            ], $received[$id] ?? []);
        }
        $page->setCollection(collect($rows));

        return $page;
    }
}
