<?php

namespace KaevCMS\Modules\Shop\Services;

use App\Models\GameServer;
use App\Services\GameAssets\GameItemCatalog;
use KaevCMS\Modules\Shop\Models\ShopProduct;

/** Icons and item names shared by the player catalog and the administration cards. */
final class ShopProductArtwork
{
    public function __construct(private readonly GameItemCatalog $catalog) {}

    /**
     * @param  iterable<ShopProduct>  $products  Products with bundleItems already loaded.
     * @return array<int, array{items: list<array{icon:?string}>, remaining:int, contents: list<array{item_id:int, quantity:int, name:string, icon:?string}>}>
     */
    public function forProducts(GameServer $server, iterable $products): array
    {
        $artwork = [];
        $known = [];
        foreach ($products as $product) {
            $contents = [];
            foreach ($product->contents() as $row) {
                $itemId = $row['item_id'];
                if ($itemId < 1) {
                    continue;
                }
                $known[$itemId] ??= $this->catalog->findForServer($server, $itemId);
                $contents[] = $row + ['name' => $known[$itemId]['name'], 'icon' => $known[$itemId]['icon']];
            }
            $preview = array_map(static fn (array $row): array => ['icon' => $row['icon']], array_slice($contents, 0, 3));
            $artwork[(int) $product->id] = [
                'items' => $preview,
                'remaining' => max(0, count($contents) - count($preview)),
                'contents' => $contents,
            ];
        }

        return $artwork;
    }
}
