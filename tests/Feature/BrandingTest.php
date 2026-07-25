<?php

namespace Tests\Feature;

use App\Models\CmsSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_kaevcms_is_the_default_product_brand(): void
    {
        $appConfig = (string) file_get_contents(config_path('app.php'));
        $cmsConfig = (string) file_get_contents(config_path('cms.php'));

        $this->assertStringContainsString("env('APP_NAME', 'KaevCMS')", $appConfig);
        $this->assertStringContainsString("'name' => 'KaevCMS'", $cmsConfig);
        $this->assertStringContainsString("'© 2026 KaevCMS'", $cmsConfig);

        config()->set('app.name', 'KaevCMS');
        config()->set('cms.site_defaults.name', 'KaevCMS');
        config()->set('cms.site_defaults.footer_text', '© 2026 KaevCMS');
        config()->set('cms.site_defaults.translations.ru.name', 'KaevCMS');
        config()->set('cms.site_defaults.translations.ru.footer_text', '© 2026 KaevCMS');

        $this->get('/')
            ->assertOk()
            ->assertSee('KaevCMS')
            ->assertSee('© 2026 KaevCMS')
            ->assertDontSee('L2Forge CMS');
    }

    public function test_rebrand_migration_updates_only_legacy_default_values(): void
    {
        CmsSetting::query()->insert([
            ['key' => 'site.name', 'value' => 'L2Forge CMS'],
            ['key' => 'site.name.en', 'value' => 'L2Forge CMS'],
            ['key' => 'site.name.de', 'value' => 'Mein eigener Server'],
            ['key' => 'site.footer_text', 'value' => '© 2026 L2Forge-CMS'],
            ['key' => 'site.footer_text.en', 'value' => '© 2026 L2Forge CMS'],
            ['key' => 'site.footer_text.de', 'value' => 'Eigener Footer'],
            ['key' => 'mail.from_name', 'value' => 'L2Forge CMS'],
        ]);

        $migration = require database_path('migrations/2026_07_18_000200_rebrand_l2forge_to_kaevcms.php');
        $migration->up();

        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name', 'value' => 'KaevCMS']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name.en', 'value' => 'KaevCMS']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name.de', 'value' => 'Mein eigener Server']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.footer_text', 'value' => '© 2026 KaevCMS']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.footer_text.en', 'value' => '© 2026 KaevCMS']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.footer_text.de', 'value' => 'Eigener Footer']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'mail.from_name', 'value' => 'KaevCMS']);
    }

    public function test_new_console_brand_is_primary(): void
    {
        $this->assertSame(0, Artisan::call('kaevcms:about'));
        $this->assertStringContainsString('KaevCMS', Artisan::output());
    }

    public function test_legacy_about_aliases_remain_available(): void
    {
        foreach (['l2forge:about', 'cms:about'] as $legacyCommand) {
            $this->assertSame(0, Artisan::call($legacyCommand));

            $output = Artisan::output();

            $this->assertStringContainsString('deprecated', $output);
            $this->assertStringContainsString('kaevcms:about', $output);
            $this->assertStringContainsString('KaevCMS', $output);
        }
    }

    public function test_package_metadata_uses_kaevcms_name(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $package = json_decode((string) file_get_contents(base_path('package.json')), true);

        $this->assertSame('kaevcms/cms', $composer['name'] ?? null);
        $this->assertSame('kaevcms-browser-tests', $package['name'] ?? null);
        $this->assertContains('kaevcms', $composer['keywords'] ?? []);
    }
}
