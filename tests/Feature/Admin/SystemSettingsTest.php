<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Services\Servers\ServerMonitorSettings;
use App\Support\KaevCMS;
use App\Support\PasswordHashing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_information_is_available_only_to_administrators(): void
    {
        $this->get('/admin/settings/system')
            ->assertRedirect(route('admin.login'));

        $admin = Admin::query()->create([
            'name' => 'System Admin',
            'email' => 'system@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        config()->set('app.key', 'base64:THIS_MUST_NOT_BE_RENDERED_IN_SYSTEM_INFORMATION');

        $hashLabel = PasswordHashing::label();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/system')
            ->assertOk()
            ->assertSee('Состояние компонентов')
            ->assertSee('Системные процессы')
            ->assertSee('Ожидающие задания')
            ->assertSee('Ошибки очереди')
            ->assertDontSee('Очередь почты')
            ->assertSee('Состояние Scheduler:')
            ->assertSee('Расширения PHP')
            ->assertSee(KaevCMS::version())
            ->assertSee(PHP_VERSION)
            ->assertSee(app()->version())
            ->assertSee('Тип хеша')
            ->assertSee('Шифрование APP_KEY')
            ->assertSee('Зашифрованные секреты')
            ->assertSee($hashLabel)
            ->assertSee('Разделы настроек')
            ->assertDontSee('THIS_MUST_NOT_BE_RENDERED_IN_SYSTEM_INFORMATION')
            ->assertDontSee('Адрес панели управления')
            ->assertDontSee('Мониторинг серверов');
    }

    public function test_system_information_creates_missing_upload_directories_before_reporting_write_access(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Upload Health Admin',
            'email' => 'upload-health@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $uploadRoot = storage_path('framework/testing/system-uploads-'.Str::uuid());
        File::deleteDirectory($uploadRoot);

        config()->set('cms.news.uploads_path', $uploadRoot);
        config()->set('cms.pages.uploads_path', $uploadRoot);
        config()->set('cms.settings.uploads_path', $uploadRoot);
        config()->set('cms.account_avatars.uploads_path', $uploadRoot.'/account-avatars');
        config()->set('cms.game_assets.uploads_path', $uploadRoot.'/game-assets');

        try {
            $this->actingAs($admin, 'admin')
                ->get('/admin/settings/system')
                ->assertOk()
                ->assertSee('Загрузка изображений новостей')
                ->assertSee('Загрузка изображений страниц')
                ->assertSee('Загрузка логотипа и favicon');

            foreach ([
                $uploadRoot.'/news',
                $uploadRoot.'/pages',
                $uploadRoot.'/settings',
                $uploadRoot.'/account-avatars',
                $uploadRoot.'/game-assets',
            ] as $directory) {
                $this->assertDirectoryExists($directory);
                $this->assertTrue(is_writable($directory), "Directory is not writable: {$directory}");
            }
        } finally {
            File::deleteDirectory($uploadRoot);
        }
    }

    public function test_pdo_sqlite_is_optional_when_the_cms_uses_mysql(): void
    {
        $admin = Admin::query()->create([
            'name' => 'MySQL Extension Admin',
            'email' => 'mysql-extension@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $connection = (string) config('database.default');
        $driverKey = "database.connections.{$connection}.driver";
        $originalDriver = config($driverKey);

        config()->set($driverKey, 'mysql');

        try {
            $this->actingAs($admin, 'admin')
                ->get('/admin/settings/system')
                ->assertOk()
                ->assertSeeInOrder(['pdo_mysql', 'Обязательно'])
                ->assertSeeInOrder(['pdo_sqlite', 'Необязательно']);
        } finally {
            config()->set($driverKey, $originalDriver);
        }
    }

    public function test_system_information_reports_sqlite_safety_and_proxy_configuration(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Infrastructure Admin',
            'email' => 'infrastructure@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        config()->set('infrastructure.trusted_proxies', '10.0.0.0/8, invalid-proxy');

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/system')
            ->assertOk()
            ->assertSee('Доверенные прокси')
            ->assertSee('Предупреждение конфигурации')
            ->assertSee('Некоторые значения TRUSTED_PROXIES указаны неверно и были проигнорированы.')
            ->assertSee('Ожидание блокировки')
            ->assertSee('5000 ms')
            ->assertSee('Режим журнала')
            ->assertSee('Синхронизация записи')
            ->assertSee('Доверенные прокси: CONFIGURED')
            ->assertDontSee('10.0.0.0/8');
    }

    public function test_system_information_warns_when_sqlite_is_used_in_production(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Production Database Admin',
            'email' => 'production-database@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->app->instance('env', 'production');

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/system')
            ->assertOk()
            ->assertSee('SQLite используется в рабочем режиме.')
            ->assertSee('Для публичного сайта используйте MySQL или MariaDB.')
            ->assertSee('Тестовая база в рабочем режиме');
    }

    public function test_system_information_explains_bcrypt_only_when_argon2id_is_unavailable(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Hash Admin',
            'email' => 'hash@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        config()->set('hashing.driver', 'bcrypt');

        $response = $this->actingAs($admin, 'admin')
            ->get('/admin/settings/system')
            ->assertOk()
            ->assertSee('Тип хеша')
            ->assertSee('Шифрование APP_KEY')
            ->assertSee('Зашифрованные секреты')
            ->assertSee('bcrypt');

        if (PasswordHashing::argon2idSupported()) {
            $response->assertDontSee('Argon2id не поддерживается системой.');
        } else {
            $response->assertSee('Argon2id не поддерживается системой.');
        }
    }

    public function test_administrator_panel_page_contains_address_and_monitoring_settings(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Panel Settings Admin',
            'email' => 'panel-settings@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/admin-panel')
            ->assertOk()
            ->assertSee('Панель администратора')
            ->assertSee('Адрес панели управления')
            ->assertSee('Мониторинг серверов')
            ->assertSee('Интервал обновления статуса')
            ->assertSee('php artisan kaevcms:admin-path --reset')
            ->assertDontSee('Состояние компонентов')
            ->assertDontSee('Расширения PHP');
    }

    public function test_server_monitor_refresh_interval_can_be_saved_from_administrator_panel_page(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Monitor Settings Admin',
            'email' => 'monitor-settings@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/admin-panel')
            ->assertOk()
            ->assertSee('Мониторинг серверов')
            ->assertSee('Интервал обновления статуса')
            ->assertSee('Как часто CMS может повторно проверять доступность LoginServer и GameServer')
            ->assertSee('30 секунд')
            ->assertSee('1 минута')
            ->assertSee('2 минуты')
            ->assertSee('5 минут');

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/admin-panel/monitoring', [
                'refresh_interval_seconds' => 120,
            ])
            ->assertRedirect(route('admin.settings.admin-panel'))
            ->assertSessionHas('status', 'Настройки мониторинга серверов сохранены.');

        $this->assertDatabaseHas('cms_settings', [
            'key' => ServerMonitorSettings::KEY_REFRESH_INTERVAL_SECONDS,
            'value' => '120',
        ]);
        $this->assertSame(120, app(ServerMonitorSettings::class)->refreshIntervalSeconds());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.server_monitor_updated',
            'result' => 'success',
        ]);
    }

    public function test_server_monitor_refresh_interval_accepts_only_safe_options(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Monitor Validation Admin',
            'email' => 'monitor-validation@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/admin-panel')
            ->put('/admin/settings/admin-panel/monitoring', [
                'refresh_interval_seconds' => 15,
            ])
            ->assertRedirect('/admin/settings/admin-panel')
            ->assertSessionHasErrors('refresh_interval_seconds');

        $this->assertDatabaseMissing('cms_settings', [
            'key' => ServerMonitorSettings::KEY_REFRESH_INTERVAL_SECONDS,
        ]);
    }

    public function test_legacy_system_monitoring_endpoint_is_not_registered(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Legacy Monitor Admin',
            'email' => 'legacy-monitor@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/system/monitoring', [
                'refresh_interval_seconds' => 300,
            ])
            ->assertNotFound();

        $this->assertSame(60, app(ServerMonitorSettings::class)->refreshIntervalSeconds());
    }

    public function test_version_is_read_from_the_root_version_file(): void
    {
        $versionPath = base_path('VERSION');

        $this->assertFileExists($versionPath);
        $this->assertSame(trim((string) file_get_contents($versionPath)), KaevCMS::version());
        $this->assertMatchesRegularExpression(
            '/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/',
            KaevCMS::version(),
        );
    }
}
