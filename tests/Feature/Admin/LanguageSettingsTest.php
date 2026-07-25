<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Services\Localization\LanguageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LanguageSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_open_language_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/languages')
            ->assertOk()
            ->assertSee('Языки')
            ->assertSee('Русский')
            ->assertSee('English')
            ->assertSee('Встроен')
            ->assertSee('Язык сайта по умолчанию');
    }

    public function test_administrator_can_change_default_and_fallback_languages(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/languages', [
                'enabled_locales' => ['ru', 'en'],
                'default_locale' => 'en',
                'fallback_locale' => 'ru',
            ])
            ->assertRedirect(route('admin.settings.languages'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => LanguageManager::KEY_DEFAULT,
            'value' => 'en',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => LanguageManager::KEY_FALLBACK,
            'value' => 'ru',
        ]);
        $this->assertSame(['ru', 'en'], json_decode((string) CmsSetting::query()
            ->where('key', LanguageManager::KEY_ENABLED)
            ->value('value'), true));

        $this->get('/')
            ->assertOk()
            ->assertSee('Home')
            ->assertSee('News');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.languages_updated',
            'result' => 'success',
        ]);
    }

    public function test_default_and_fallback_languages_must_be_enabled(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/languages')
            ->put('/admin/settings/languages', [
                'enabled_locales' => ['ru'],
                'default_locale' => 'en',
                'fallback_locale' => 'en',
            ])
            ->assertRedirect('/admin/settings/languages')
            ->assertSessionHasErrors(['default_locale', 'fallback_locale']);
    }

    public function test_reviewed_additional_language_pack_is_discovered_without_a_migration(): void
    {
        $metadataDirectory = lang_path('de');
        $metadataPath = $metadataDirectory.DIRECTORY_SEPARATOR.'language.php';
        $jsonPath = lang_path('de.json');

        File::ensureDirectoryExists($metadataDirectory);
        File::put($metadataPath, <<<'PHP'
<?php

return [
    'code' => 'de',
    'name' => 'German',
    'native_name' => 'Deutsch',
    'direction' => 'ltr',
    'fallback' => 'en',
    'author' => 'Test pack',
];
PHP);
        File::put($jsonPath, json_encode(['Home' => 'Startseite'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $this->app->forgetInstance(LanguageManager::class);
            $languages = $this->app->make(LanguageManager::class);

            $this->assertTrue($languages->isInstalled('de'));
            $this->assertSame('Deutsch', $languages->language('de')['native_name']);

            $languages->update(['ru', 'en', 'de'], 'ru', 'en');
            $this->assertTrue($languages->isEnabled('de'));
            $this->assertContains('de', $languages->enabledCodes());
        } finally {
            File::delete($jsonPath);
            File::deleteDirectory($metadataDirectory);
            $this->app->forgetInstance(LanguageManager::class);
        }
    }

    public function test_admin_language_switch_is_saved_to_the_account(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/language/en')
            ->assertRedirect();

        $this->assertSame('en', $admin->fresh()->locale);

        $this->actingAs($admin->fresh(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Control panel')
            ->assertSee('Dashboard');
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'locale' => 'ru',
        ]);
    }
}
