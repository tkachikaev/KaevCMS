<?php

namespace KaevCMS\Modules\PromoCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\RewardInventoryItem;
use App\Services\GameAssets\GameAssetUrlResolver;
use Illuminate\View\View;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;

final class AdminPromoCodeActivationController extends Controller
{
    public function __invoke(GameAssetUrlResolver $assets): View
    {
        $activations = PromoCodeActivation::query()
            ->with(['promoCode.rewards', 'user', 'gameServer.translations', 'rewardGrant.items'])
            ->latest('activated_at')
            ->latest('id')
            ->paginate(30);
        $activationItems = [];

        foreach ($activations as $activation) {
            if ($activation->reward_inventory_grant_id === null) {
                $activationItems[$activation->id] = [];

                continue;
            }

            $activationItems[$activation->id] = $activation->rewardGrant->items
                ->map(static fn (RewardInventoryItem $item): array => [
                    'item_id' => $item->item_id,
                    'amount' => $item->amount,
                    'name' => $item->displayName($activation->game_server_id),
                    'status' => $item->status,
                    'icon_url' => $assets->itemIcon($activation->gameServer, $item->item_id),
                ])
                ->values()
                ->all();
        }

        /** @var view-string $view */
        $view = 'module-promo-codes::admin.activations';

        return view($view, [
            'activations' => $activations,
            'activationItems' => $activationItems,
        ]);
    }
}
