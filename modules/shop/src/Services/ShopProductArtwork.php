<?php

namespace KaevCMS\Modules\Shop\Services;

use App\Models\GameServer;
use App\Services\GameAssets\GameItemCatalog;
use KaevCMS\Modules\Shop\Models\ShopProduct;

/** Build a small shared visual preview for player and administration cards. */
final class ShopProductArtwork
{
    public function __construct(private readonly GameItemCatalog $catalog) {}

    /**
     * @param iterable<ShopProduct> $products Products with bundleItems already loaded.
     * @return array<int, array{items: list<array{icon:?string}>, remaining:int}>
     */
    public function forProducts(GameServer $server, iterable $products): array
    {
        $artwork = [];
        $icons = [];
        foreach ($products as $product) {
            $itemIds = $product->type === 'item'
                ? [(int) $product->item_id]
                : $product->bundleItems->pluck('item_id')->map(static fn ($id): int => (int) $id)->all();
            $preview = [];
            foreach (array_slice($itemIds, 0, 3) as $itemId) {
                if ($itemId < 1) {
                    continue;
                }
                if (! array_key_exists($itemId, $icons)) {
                    $icons[$itemId] = $this->catalog->findForServer($server, $itemId)['icon'];
                }
                $preview[] = ['icon' => $icons[$itemId]];
            }
            $artwork[(int) $product->id] = [
                'items' => $preview,
                'remaining' => max(0, count($itemIds) - count($preview)),
            ];
        }

        return $artwork;
    }
}
