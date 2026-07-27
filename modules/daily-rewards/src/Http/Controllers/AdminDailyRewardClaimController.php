<?php

namespace KaevCMS\Modules\DailyRewards\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RewardInventoryItem;
use App\Services\GameAssets\GameAssetUrlResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;

final class AdminDailyRewardClaimController extends Controller
{
    public function __invoke(Request $request, GameAssetUrlResolver $assets): View
    {
        $query = DailyRewardClaim::query()
            ->with(['calendar.gameServer.translations', 'day', 'user', 'gameAccount', 'rewardGrant.items'])
            ->latest('claimed_at')
            ->latest('id');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(static function ($builder) use ($search): void {
                $builder->where('user_email', 'like', '%'.$search.'%')
                    ->orWhere('game_account_login', 'like', '%'.$search.'%');
            });
        }

        $claims = $query->paginate(30)->withQueryString();
        $claimItems = [];
        foreach ($claims as $claim) {
            $grantItems = $claim->rewardGrant?->items;
            if ($grantItems !== null && $grantItems->isNotEmpty()) {
                $claimItems[$claim->id] = $grantItems
                    ->map(static fn (RewardInventoryItem $item): array => [
                        'item_id' => $item->item_id,
                        'amount' => $item->amount,
                        'name' => $item->displayName($claim->game_server_id),
                        'status' => $item->status,
                        'icon_url' => $assets->itemIcon($claim->gameServer, $item->item_id),
                    ])
                    ->values()
                    ->all();

                continue;
            }

            $claimItems[$claim->id] = collect((array) $claim->items_snapshot)
                ->map(static fn (mixed $item): array => [
                    'item_id' => (int) (is_array($item) ? ($item['item_id'] ?? 0) : 0),
                    'amount' => (int) (is_array($item) ? ($item['amount'] ?? 0) : 0),
                    'name' => (string) (is_array($item) ? ($item['name'] ?? '') : ''),
                    'status' => null,
                ])
                ->map(fn (array $item): array => array_merge($item, [
                    'icon_url' => $assets->itemIcon($claim->gameServer, $item['item_id']),
                ]))
                ->values()
                ->all();
        }

        return view('module-daily-rewards::admin.claims', [
            'claims' => $claims,
            'claimItems' => $claimItems,
            'search' => $search,
        ]);
    }
}
