<?php

namespace Tests\Unit;

use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GameItemCatalogTest extends TestCase
{
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
}
