<?php

namespace Tests\Unit;

use App\Models\GameServer;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\GameAssets\GameItemProfileCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class GameItemCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_catalog_services_are_reused_within_the_application_lifetime(): void
    {
        $this->assertSame(app(GameItemProfileCatalog::class), app(GameItemProfileCatalog::class));
        $this->assertSame(app(GameAssetUrlResolver::class), app(GameAssetUrlResolver::class));
        $this->assertSame(app(GameItemCatalog::class), app(GameItemCatalog::class));
    }

    public function test_bundled_catalog_resolves_names_in_the_active_locale(): void
    {
        $catalog = app(GameItemCatalog::class);

        App::setLocale('ru');
        $this->assertSame('Адена', $catalog->displayName(1, 57));

        App::setLocale('en');
        $this->assertSame('Adena', app(GameItemCatalog::class)->displayName(1, 57));
    }

    public function test_server_override_precedes_common_name_and_fallback_locale_is_supported(): void
    {
        $locale = 'catalog-test';
        $directory = lang_path($locale);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/items.php', <<<'PHP'
<?php

return [
    'common' => [90000 => 'Common Coin'],
    'servers' => [7 => [90000 => 'Server Coin']],
];
PHP);

        try {
            App::setLocale($locale);
            $catalog = app(GameItemCatalog::class);

            $this->assertSame('Server Coin', $catalog->displayName(7, 90000));
            $this->assertSame('Common Coin', $catalog->displayName(8, 90000));
            $this->assertSame('Adena', $catalog->displayName(8, 57));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_unknown_player_facing_item_uses_generic_name_without_exposing_id(): void
    {
        App::setLocale('ru');

        $name = app(GameItemCatalog::class)->displayName(1, 987654321);

        $this->assertSame('Игровой предмет', $name);
        $this->assertStringNotContainsString('987654321', $name);
    }

    public function test_profile_catalog_uses_manual_russian_name_then_falls_back_to_catalog_english(): void
    {
        [$catalogDirectory, $assetDirectory] = $this->profileFixture([
            57 => ['icon' => 'etc_adena_i00', 'name_en' => 'Adena from JSON'],
            907 => ['icon' => 'accessary_necklace_of_anguish_i00', 'name_en' => 'Necklace of Anguish'],
        ], ['etc_adena_i00', 'accessary_necklace_of_anguish_i00']);

        try {
            $catalog = app(GameItemCatalog::class);

            $adena = $catalog->find(profile: 'interlude', itemId: 57, locale: 'ru');
            $adenaEnglish = $catalog->find(profile: 'interlude', itemId: 57, locale: 'en');
            $necklace = $catalog->find(profile: 'interlude', itemId: 907, locale: 'ru');

            $this->assertSame('Адена', $adena['name']);
            $this->assertSame('Adena', $adenaEnglish['name']);
            $this->assertSame('Necklace of Anguish', $necklace['name']);
            $this->assertStringEndsWith(
                '/game-assets/items/accessary_necklace_of_anguish_i00.webp',
                (string) $necklace['icon'],
            );
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    public function test_chronicle_profiles_are_isolated_and_share_external_icons(): void
    {
        $suffix = (string) Str::uuid();
        $catalogDirectory = storage_path('framework/testing/item-catalog-'.$suffix);
        $assetDirectory = storage_path('framework/testing/item-assets-'.$suffix);
        File::ensureDirectoryExists($catalogDirectory);
        File::ensureDirectoryExists($assetDirectory.'/items');
        File::put($catalogDirectory.'/classic.json', json_encode([
            90010 => ['icon' => 'shared_chronicle_i00', 'name_en' => 'Classic Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($catalogDirectory.'/high-five.json', json_encode([
            90010 => ['icon' => 'shared_chronicle_i00', 'name_en' => 'High Five Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($catalogDirectory.'/shine-maker.json', json_encode([
            90010 => ['icon' => 'shared_chronicle_i00', 'name_en' => 'Shine Maker Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($assetDirectory.'/items/shared_chronicle_i00.webp', 'shared');
        config()->set('cms.game_assets.item_catalog_path', $catalogDirectory);
        config()->set('cms.game_assets.standard_path', $assetDirectory);

        $classic = new GameServer(['chronicle' => 'Classic 3.5 Tales Untold']);
        $classic->setAttribute('id', 10);
        $highFive = new GameServer(['chronicle' => 'High Five']);
        $highFive->setAttribute('id', 11);
        $shineMaker = new GameServer(['chronicle' => 'ShineMaker']);
        $shineMaker->setAttribute('id', 12);

        try {
            $catalog = app(GameItemCatalog::class);
            $classicItem = $catalog->findForServer($classic, 90010, 'ru');
            $highFiveItem = $catalog->findForServer($highFive, 90010, 'ru');
            $shineMakerItem = $catalog->findForServer($shineMaker, 90010, 'ru');

            $this->assertSame('Classic Token', $classicItem['name']);
            $this->assertSame('High Five Token', $highFiveItem['name']);
            $this->assertSame('Shine Maker Token', $shineMakerItem['name']);
            $this->assertSame($classicItem['icon'], $highFiveItem['icon']);
            $this->assertSame($classicItem['icon'], $shineMakerItem['icon']);
            $this->assertStringEndsWith(
                '/game-assets/items/shared_chronicle_i00.webp',
                (string) $classicItem['icon'],
            );
            $profiles = app(GameItemProfileCatalog::class);
            $this->assertSame('classic', $profiles->normalizeProfile('Classic 3.5 Tales Untold'));
            $this->assertSame('high-five', $profiles->normalizeProfile('highFive'));
            $this->assertSame('shine-maker', $profiles->normalizeProfile('Shine Maker'));
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    public function test_integer_server_id_uses_the_servers_actual_item_profile(): void
    {
        $suffix = (string) Str::uuid();
        $catalogDirectory = storage_path('framework/testing/item-catalog-'.$suffix);
        $assetDirectory = storage_path('framework/testing/item-assets-'.$suffix);
        File::ensureDirectoryExists($catalogDirectory);
        File::ensureDirectoryExists($assetDirectory.'/items');
        File::put($catalogDirectory.'/interlude.json', json_encode([
            90011 => ['icon' => 'interlude_token_i00', 'name_en' => 'Interlude Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($catalogDirectory.'/classic.json', json_encode([
            90011 => ['icon' => 'classic_token_i00', 'name_en' => 'Classic Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($assetDirectory.'/items/classic_token_i00.webp', 'classic');
        config()->set('cms.game_assets.item_catalog_path', $catalogDirectory);
        config()->set('cms.game_assets.standard_path', $assetDirectory);
        $server = GameServer::factory()->create(['chronicle' => 'Classic']);

        try {
            $item = app(GameItemCatalog::class)->findForServer($server->id, 90011, 'en');

            $this->assertSame('Classic Token', $item['name']);
            $this->assertStringEndsWith('/game-assets/items/classic_token_i00.webp', (string) $item['icon']);
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    public function test_legacy_profile_item_folder_remains_a_fallback(): void
    {
        $suffix = (string) Str::uuid();
        $catalogDirectory = storage_path('framework/testing/item-catalog-'.$suffix);
        $assetDirectory = storage_path('framework/testing/item-assets-'.$suffix);
        File::ensureDirectoryExists($catalogDirectory);
        File::ensureDirectoryExists($assetDirectory.'/items/interlude');
        File::put($catalogDirectory.'/interlude.json', json_encode([
            90012 => ['icon' => 'legacy_token_i00', 'name_en' => 'Legacy Token'],
        ], JSON_THROW_ON_ERROR));
        File::put($assetDirectory.'/items/interlude/legacy_token_i00.webp', 'legacy');
        config()->set('cms.game_assets.item_catalog_path', $catalogDirectory);
        config()->set('cms.game_assets.standard_path', $assetDirectory);

        try {
            $item = app(GameItemCatalog::class)->find('interlude', 90012, 'en');

            $this->assertSame('Legacy Token', $item['name']);
            $this->assertStringEndsWith(
                '/game-assets/items/interlude/legacy_token_i00.webp',
                (string) $item['icon'],
            );
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    public function test_a_different_chronicle_does_not_read_the_interlude_profile(): void
    {
        [$catalogDirectory, $assetDirectory] = $this->profileFixture([
            907 => ['icon' => 'accessary_necklace_of_anguish_i00', 'name_en' => 'Necklace of Anguish'],
        ], ['accessary_necklace_of_anguish_i00']);
        $server = new GameServer(['chronicle' => 'High Five']);
        $server->setAttribute('id', 7);

        try {
            $catalog = app(GameItemCatalog::class);

            $this->assertNull($catalog->knownName($server, 907, 'en'));
            $this->assertNull(app(GameAssetUrlResolver::class)->itemIcon($server, 907));
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    public function test_profile_catalog_has_id_fallback_missing_icon_and_shared_icon_support(): void
    {
        [$catalogDirectory, $assetDirectory] = $this->profileFixture([
            90001 => ['icon' => 'shared_token_i00', 'name_en' => 'First Token'],
            90002 => ['icon' => 'shared_token_i00', 'name_en' => 'Second Token'],
            90003 => ['icon' => 'missing_i00', 'name_en' => 'Missing Icon'],
        ], ['shared_token_i00']);

        try {
            $catalog = app(GameItemCatalog::class);

            $first = $catalog->find('interlude', 90001, 'en');
            $second = $catalog->find('interlude', 90002, 'en');
            $missingIcon = $catalog->find('interlude', 90003, 'en');
            $unknownRu = $catalog->find('interlude', 987654321, 'ru');
            $unknownEn = $catalog->find('interlude', 987654321, 'en');

            $this->assertSame($first['icon'], $second['icon']);
            $this->assertNull($missingIcon['icon']);
            $this->assertSame('Предмет #987654321', $unknownRu['name']);
            $this->assertSame('Item #987654321', $unknownEn['name']);
        } finally {
            File::deleteDirectory($catalogDirectory);
            File::deleteDirectory($assetDirectory);
        }
    }

    /**
     * @param  array<int, array{icon:string,name_en:string}>  $items
     * @param  list<string>  $icons
     * @return array{string,string}
     */
    private function profileFixture(array $items, array $icons): array
    {
        $suffix = (string) Str::uuid();
        $catalogDirectory = storage_path('framework/testing/item-catalog-'.$suffix);
        $assetDirectory = storage_path('framework/testing/item-assets-'.$suffix);
        File::ensureDirectoryExists($catalogDirectory);
        File::ensureDirectoryExists($assetDirectory.'/items');
        File::put(
            $catalogDirectory.'/interlude.json',
            json_encode((object) $items, JSON_THROW_ON_ERROR),
        );

        foreach ($icons as $icon) {
            File::put($assetDirectory.'/items/'.$icon.'.webp', $icon);
        }

        config()->set('cms.game_assets.item_catalog_path', $catalogDirectory);
        config()->set('cms.game_assets.standard_path', $assetDirectory);

        return [$catalogDirectory, $assetDirectory];
    }
}
