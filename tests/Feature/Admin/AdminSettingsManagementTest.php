<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadRoot = storage_path('framework/testing/settings-uploads');
        File::deleteDirectory($this->uploadRoot);
        config()->set('cms.settings.uploads_path', $this->uploadRoot);
        config()->set('app.name', 'KaevCMS');
        config()->set('cms.site_defaults.name', 'KaevCMS');
        config()->set('cms.site_defaults.footer_text', '© 2026 KaevCMS');
        config()->set('cms.site_defaults.translations.ru.name', 'KaevCMS');
        config()->set('cms.site_defaults.translations.ru.footer_text', '© 2026 KaevCMS');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadRoot);

        parent::tearDown();
    }

    public function test_guest_cannot_open_settings(): void
    {
        $this->get('/admin/settings')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_open_general_settings_and_see_tabs(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Настройки')
            ->assertSee('settings-section-tabs', false)
            ->assertSeeInOrder([
                'Сайт',
                'Панель администратора',
                'Регистрация',
                'Игровые аккаунты',
                'Языки',
                'Безопасность',
                'Системная информация',
            ])
            ->assertSee('© 2026 KaevCMS')
            ->assertSee('translation-tab-label', false)
            ->assertSee('translation-tab-default', false)
            ->assertSee('Сохранить настройки');
    }

    public function test_admin_can_save_general_settings_and_public_theme_uses_them(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings', [
                '_method' => 'PUT',
                'site_name' => 'L2 Eternal',
                'site_description' => 'Классический сервер Lineage II High Five x5',
                'timezone' => 'Europe/Moscow',
                'admin_email' => 'admin@example.com',
                'footer_text' => '© 2026 L2 Eternal',
                'remove_logo' => '0',
                'remove_favicon' => '0',
            ])
            ->assertRedirect(route('admin.settings.general'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name', 'value' => 'L2 Eternal']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.description', 'value' => 'Классический сервер Lineage II High Five x5']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.timezone', 'value' => 'Europe/Moscow']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.admin_email', 'value' => 'admin@example.com']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.footer_text', 'value' => '© 2026 L2 Eternal']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>L2 Eternal —', false)
            ->assertSee('content="Классический сервер Lineage II High Five x5"', false)
            ->assertSee('© 2026 L2 Eternal');

        $this->assertSame('Europe/Moscow', config('app.timezone'));
    }

    public function test_general_settings_update_preserves_public_online_visibility(): void
    {
        $admin = $this->createAdmin();
        CmsSetting::query()->updateOrCreate(
            ['key' => 'site.show_public_online'],
            ['value' => '0'],
        );

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings', [
                '_method' => 'PUT',
                'site_name' => 'KaevCMS',
                'site_description' => '',
                'timezone' => 'UTC',
                'admin_email' => '',
                'footer_text' => '© 2026 KaevCMS',
                'remove_logo' => '0',
                'remove_favicon' => '0',
            ])
            ->assertRedirect(route('admin.settings.general'));

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'site.show_public_online',
            'value' => '0',
        ]);
    }

    public function test_admin_can_upload_logo_and_favicon(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings', [
                '_method' => 'PUT',
                'site_name' => 'L2 Images',
                'site_description' => '',
                'timezone' => 'UTC',
                'admin_email' => '',
                'footer_text' => '© 2026 KaevCMS',
                'logo' => $this->pngUpload('logo.png'),
                'favicon' => $this->pngUpload('favicon.png'),
                'remove_logo' => '0',
                'remove_favicon' => '0',
            ])
            ->assertRedirect(route('admin.settings.general'));

        $logo = (string) $this->setting('site.logo');
        $favicon = (string) $this->setting('site.favicon');

        $this->assertMatchesRegularExpression('~^settings/logo/[a-f0-9-]+\.png$~', $logo);
        $this->assertMatchesRegularExpression('~^settings/favicon/[a-f0-9-]+\.png$~', $favicon);
        $this->assertFileExists($this->absoluteUploadPath($logo));
        $this->assertFileExists($this->absoluteUploadPath($favicon));

        $this->get('/')
            ->assertOk()
            ->assertSee('/uploads/'.$logo, false)
            ->assertSee('/uploads/'.$favicon, false);
    }

    public function test_replacing_and_removing_settings_images_deletes_old_files(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')->post('/admin/settings', [
            '_method' => 'PUT',
            'site_name' => 'L2 Images',
            'site_description' => '',
            'timezone' => 'UTC',
            'admin_email' => '',
            'footer_text' => '',
            'logo' => $this->pngUpload('first-logo.png'),
            'favicon' => $this->pngUpload('first-favicon.png'),
            'remove_logo' => '0',
            'remove_favicon' => '0',
        ]);

        $oldLogo = (string) $this->setting('site.logo');
        $oldFavicon = (string) $this->setting('site.favicon');

        $this->actingAs($admin, 'admin')->post('/admin/settings', [
            '_method' => 'PUT',
            'site_name' => 'L2 Images',
            'site_description' => '',
            'timezone' => 'UTC',
            'admin_email' => '',
            'footer_text' => '',
            'logo' => $this->pngUpload('second-logo.png'),
            'remove_logo' => '0',
            'remove_favicon' => '1',
        ])->assertRedirect(route('admin.settings.general'));

        $newLogo = (string) $this->setting('site.logo');

        $this->assertNotSame($oldLogo, $newLogo);
        $this->assertFileDoesNotExist($this->absoluteUploadPath($oldLogo));
        $this->assertFileDoesNotExist($this->absoluteUploadPath($oldFavicon));
        $this->assertFileExists($this->absoluteUploadPath($newLogo));
        $this->assertSame('', (string) $this->setting('site.favicon'));
    }

    public function test_svg_logo_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings', [
                '_method' => 'PUT',
                'site_name' => 'L2 Unsafe',
                'site_description' => '',
                'timezone' => 'UTC',
                'admin_email' => '',
                'footer_text' => '',
                'logo' => UploadedFile::fake()->createWithContent(
                    'logo.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
                ),
                'remove_logo' => '0',
                'remove_favicon' => '0',
            ])
            ->assertSessionHasErrors('logo');

        $this->assertDatabaseMissing('cms_settings', ['key' => 'site.logo']);
    }

    public function test_login_server_tab_opens_connection_management(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/login-server')
            ->assertOk()
            ->assertSee('Логин серверы')
            ->assertSee('Добавить логин сервер')
            ->assertSee('L2J Mobius')
            ->assertSee('RUSaCis');
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }

    private function pngUpload(string $name): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2V1sAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $png ?: '');
    }

    private function setting(string $key): ?string
    {
        return CmsSetting::query()->where('key', $key)->value('value');
    }

    private function absoluteUploadPath(string $path): string
    {
        return $this->uploadRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
