<?php

namespace KaevCMS\Modules\Shop\Services;

use App\Models\GameServer;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\Localization\LanguageManager;

/** Game item names and icons for the editor: lookup by ID and search by name. */
final class ShopItemDirectory
{
    public function __construct(
        private readonly GameItemCatalog $items,
        private readonly GameAssetUrlResolver $assets,
        private readonly LanguageManager $languages,
    ) {}

    /** @return array{id:int, name:string, icon_url:?string, known:bool, names:array<string, ?string>} */
    public function describe(GameServer $server, int $itemId): array
    {
        $names = [];
        foreach ($this->languages->enabledCodes() as $locale) {
            $names[$locale] = $this->items->knownName($server, $itemId, $locale);
        }
        $known = $this->items->knownName($server, $itemId);

        return [
            'id' => $itemId,
            'name' => $known ?? $this->items->findForServer($server, $itemId)['name'],
            'icon_url' => $this->assets->itemIcon($server, $itemId),
            'known' => $known !== null,
            'names' => $names,
        ];
    }

    /** @return list<array{id:int, name:string, icon_url:?string}> */
    public function search(GameServer $server, string $query, int $limit = 12): array
    {
        $query = trim($query);
        $ids = [];
        if (preg_match('/\A\d{1,10}\z/D', $query) === 1 && (int) $query > 0 && (int) $query <= 2147483647) {
            $ids[] = (int) $query;
        }
        if (mb_strlen($query) >= 2) {
            foreach ($this->items->search($server, $query, $limit) as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return array_map(fn (int $id): array => [
            'id' => $id,
            'name' => $this->items->findForServer($server, $id)['name'],
            'icon_url' => $this->assets->itemIcon($server, $id),
        ], array_slice($ids, 0, $limit));
    }
}
