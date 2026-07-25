<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Exceptions\GameServerDeletionConfirmationRequired;
use App\Livewire\Admin\GameServerManager;
use App\Livewire\Admin\LoginServerManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameServerSettings;
use App\Services\Localization\LanguageManager;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class ReactiveServerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
    }

    public function test_login_connection_is_checked_inside_the_livewire_drawer_without_saving(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('create')
            ->set('name', 'Primary Login')
            ->set('driver', 'l2j_mobius')
            ->set('databaseHost', '127.0.0.1')
            ->set('databasePort', '3306')
            ->set('databaseName', 'l2jmobiusinterlude')
            ->set('databaseUsername', 'l2forge')
            ->set('databasePassword', 'SecretLoginPassword')
            ->set('databaseCharset', 'utf8mb4')
            ->call('testConnection')
            ->assertSet('drawerOpen', true)
            ->assertSet('connectionReport.connected', true)
            ->assertSee('Подключение к базе данных установлено.');

        $this->assertDatabaseCount('login_servers', 0);
        $this->assertSame('l2jmobiusinterlude', $fake->connection['database'] ?? null);
    }

    public function test_saved_login_server_is_checked_on_the_card_without_opening_the_drawer(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $server = LoginServer::query()->create($this->loginServerValues());
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('testStored', $server->id)
            ->assertSet('drawerOpen', false)
            ->assertSet("cardTestResults.{$server->id}.state", 'success')
            ->assertSee('Подключение к базе данных установлено.');

        $this->assertSame('l2jmobiusinterlude', $fake->connection['database'] ?? null);
    }

    public function test_failed_login_server_card_test_changes_badge_to_not_configured(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report['connected'] = false;
        $fake->report['compatible'] = null;
        $fake->report['error'] = 'Connection refused';
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $server = LoginServer::query()->create($this->loginServerValues());
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('testStored', $server->id)
            ->assertSet("cardTestResults.{$server->id}.state", 'failed')
            ->assertSee('Не настроено')
            ->assertSee('Не удалось подключиться к базе данных.');

        $this->assertSame('not_configured', $server->fresh()?->database_status);
        $this->assertSame('connection_failed', $server->fresh()?->database_error);
    }

    public function test_login_server_can_be_created_from_the_livewire_drawer(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('create')
            ->set('name', 'Primary Login')
            ->set('driver', 'l2j_mobius')
            ->set('databaseHost', '127.0.0.1')
            ->set('databasePort', '3306')
            ->set('databaseName', 'l2jmobiusinterlude')
            ->set('databaseUsername', 'l2forge')
            ->set('databasePassword', 'SecretLoginPassword')
            ->set('databaseCharset', 'utf8mb4')
            ->call('save')
            ->assertSet('drawerOpen', true)
            ->assertSet('editingId', fn (?int $id): bool => $id !== null)
            ->assertSee('LoginServer добавлен.');

        $server = LoginServer::query()->firstOrFail();

        $this->assertSame('Primary Login', $server->name);
        $this->assertSame('SecretLoginPassword', $server->databasePassword());
        $this->assertNotSame('SecretLoginPassword', $server->getRawOriginal('database_password'));
        $this->assertSame('configured', $server->database_status);
        $this->assertNotNull($server->database_checked_at);
    }

    public function test_random_login_server_credentials_are_saved_as_not_configured(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report = [
            'connected' => false,
            'compatible' => null,
            'server_version' => null,
            'error' => 'Access denied',
            'checks' => [],
        ];
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('create')
            ->set('name', 'Random Login')
            ->set('driver', 'l2j_mobius')
            ->set('databaseHost', 'random.invalid')
            ->set('databasePort', '3306')
            ->set('databaseName', 'random_database')
            ->set('databaseUsername', 'random_user')
            ->set('databasePassword', 'RandomPassword')
            ->set('databaseCharset', 'utf8mb4')
            ->call('save')
            ->assertSee('Не настроено')
            ->assertSee('status-badge-danger', false);

        $server = LoginServer::query()->where('name', 'Random Login')->firstOrFail();
        $this->assertSame('not_configured', $server->database_status);
        $this->assertSame('connection_failed', $server->database_error);
        $this->assertNotNull($server->database_checked_at);
    }

    public function test_public_online_visibility_is_managed_from_game_servers(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->assertSet('showPublicOnline', true)
            ->assertSee('Показывать онлайн на сайте')
            ->call('setShowPublicOnline', false)
            ->assertSet('showPublicOnline', false)
            ->assertSee('Показ онлайна на публичном сайте отключён.');

        $this->assertDatabaseHas('cms_settings', [
            'key' => SiteSettings::KEY_SHOW_PUBLIC_ONLINE,
            'value' => '0',
        ]);

        $this->get(route('admin.settings.general'))
            ->assertOk()
            ->assertDontSee('show_public_online', false);
    }

    public function test_game_connection_is_checked_inside_the_livewire_drawer_without_saving(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $gameServer->id)
            ->set('connectionEnabled', true)
            ->set('loginServerId', (string) $loginServer->id)
            ->set('driver', 'l2j_mobius_ct0_interlude')
            ->set('useLoginServerConnection', true)
            ->call('testConnection')
            ->assertSet('drawerOpen', true)
            ->assertSet('connectionReport.connected', true)
            ->assertSee('Подключение к базе данных установлено.');

        $this->assertNull($gameServer->fresh()->driver);
        $this->assertSame('l2jmobiusinterlude', $fake->connection['database'] ?? null);
        $this->assertSame('characters', $fake->requirements[0]['table'] ?? null);
    }

    public function test_saved_game_server_is_checked_on_the_card_without_opening_the_drawer(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report['connected'] = false;
        $fake->report['compatible'] = null;
        $fake->report['error'] = 'Connection refused';
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update([
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('testStored', $gameServer->id)
            ->assertSet('drawerOpen', false)
            ->assertSet("cardTestResults.{$gameServer->id}.state", 'failed')
            ->assertSee('Не настроено')
            ->assertSee('Не удалось подключиться к базе данных.');

        $this->assertSame('not_configured', $gameServer->fresh()?->database_status);
    }

    public function test_game_server_can_be_saved_from_the_livewire_drawer(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $gameServer->id)
            ->set('translations.ru', 'Interlude x5')
            ->set('serverRates', 'x5')
            ->set('serverChronicle', 'Interlude')
            ->set('serverMode', 'PvP')
            ->set('connectionEnabled', true)
            ->set('loginServerId', (string) $loginServer->id)
            ->set('driver', 'l2j_mobius_ct0_interlude')
            ->set('useLoginServerConnection', true)
            ->call('save')
            ->assertSet('drawerOpen', true)
            ->assertSee('Настройки игрового сервера сохранены.');

        $gameServer->refresh();

        $this->assertSame('Interlude x5', $gameServer->name);
        $this->assertSame('x5', $gameServer->rates);
        $this->assertSame($loginServer->id, $gameServer->login_server_id);
        $this->assertSame('l2j_mobius_ct0_interlude', $gameServer->driver);
        $this->assertTrue($gameServer->use_login_server_connection);
        $this->assertSame('configured', $gameServer->database_status);
        $this->assertNotNull($gameServer->database_checked_at);
    }

    public function test_game_server_maintenance_message_is_visible_only_when_enabled_and_can_be_saved(): void
    {
        $gameServer = GameServer::query()->firstOrFail();
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $gameServer->id)
            ->assertDontSee('live_game_maintenance_message_ru', false)
            ->call('setMaintenanceEnabled', true)
            ->assertSet('maintenanceEnabled', true)
            ->assertSee('live_game_maintenance_message_ru', false)
            ->set('maintenanceMessages.ru', 'Установка обновления до 15:30 МСК')
            ->call('save')
            ->assertSee('На обслуживании')
            ->assertSee('status-badge-warning', false);

        $gameServer->refresh();
        $this->assertTrue($gameServer->maintenance_enabled);
        $this->assertDatabaseHas('game_server_translations', [
            'game_server_id' => $gameServer->id,
            'locale' => 'ru',
            'maintenance_message' => 'Установка обновления до 15:30 МСК',
        ]);
    }

    public function test_newly_enabled_language_appears_for_maintenance_messages_and_is_used_publicly(): void
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
        File::put($jsonPath, json_encode([], JSON_PRETTY_PRINT));

        try {
            $this->app->forgetInstance(LanguageManager::class);
            $this->app->make(LanguageManager::class)->update(['ru', 'en', 'de'], 'ru', 'en');
            $this->app->forgetInstance(LanguageManager::class);

            $gameServer = GameServer::query()->firstOrFail();
            $this->actingAs($this->createAdmin(), 'admin');

            Livewire::test(GameServerManager::class)
                ->call('edit', $gameServer->id)
                ->call('setMaintenanceEnabled', true)
                ->assertSet('maintenanceEnabled', true)
                ->assertSee('live_game_maintenance_message_de', false)
                ->set('maintenanceMessages.de', 'Wartung bis 15:30 Uhr')
                ->call('save');

            $this->get('/de')
                ->assertOk()
                ->assertSee('Wartung bis 15:30 Uhr');
        } finally {
            File::delete($jsonPath);
            File::deleteDirectory($metadataDirectory);
            $this->app->forgetInstance(LanguageManager::class);
        }
    }

    public function test_unused_login_server_can_be_deleted_from_the_livewire_confirmation(): void
    {
        $server = LoginServer::query()->create($this->loginServerValues());
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('confirmDelete', $server->id)
            ->assertSet('confirmingDeleteId', $server->id)
            ->call('deleteServer')
            ->assertSet('confirmingDeleteId', null)
            ->assertSee('LoginServer «Primary Login» удалён.');

        $this->assertDatabaseMissing('login_servers', ['id' => $server->id]);
    }

    public function test_game_server_can_be_deleted_from_the_livewire_confirmation(): void
    {
        $server = GameServer::query()->firstOrFail();
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $server->id)
            ->assertSet('confirmingDeleteId', $server->id)
            ->call('deleteServer')
            ->assertSet('confirmingDeleteId', null)
            ->assertSee('Игровой сервер «'.$server->name.'» удалён.');

        $this->assertDatabaseMissing('game_servers', ['id' => $server->id]);
    }

    public function test_last_game_server_deletion_shows_the_account_impact_and_requires_the_confirmed_fingerprint(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update([
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Player',
            'email' => 'player-impact@example.com',
            'password' => Hash::make('Password123'),
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Impact01',
            'normalized_login' => 'impact01',
        ]);
        $this->actingAs($this->createAdmin(), 'admin');

        $this->expectException(GameServerDeletionConfirmationRequired::class);
        app(GameServerSettings::class)->delete($gameServer);
    }

    public function test_admin_can_confirm_deletion_of_the_last_game_server_after_reviewing_affected_accounts(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update([
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Player',
            'email' => 'player-delete@example.com',
            'password' => Hash::make('Password123'),
        ]);
        $account = UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Delete01',
            'normalized_login' => 'delete01',
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
            'game_login' => 'AlreadyHidden01',
            'normalized_login' => 'alreadyhidden01',
        ]);
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $gameServer->id)
            ->assertSet('deleteImpactRequiresConfirmation', true)
            ->assertSet('deleteImpactAccountCount', 1)
            ->assertSee('Это последний GameServer, подключённый к LoginServer')
            ->assertSee('Игровых аккаунтов станет недоступно: 1')
            ->call('deleteServer')
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseMissing('game_servers', ['id' => $gameServer->id]);
        $this->assertNull($account->fresh()?->registration_game_server_id);
    }

    public function test_changed_deletion_impact_requires_a_fresh_confirmation(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update([
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Player',
            'email' => 'player-change@example.com',
            'password' => Hash::make('Password123'),
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Change01',
            'normalized_login' => 'change01',
        ]);
        $this->actingAs($this->createAdmin(), 'admin');

        $component = Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $gameServer->id)
            ->assertSet('deleteImpactAccountCount', 1);

        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Change02',
            'normalized_login' => 'change02',
        ]);

        $component
            ->call('deleteServer')
            ->assertSet('confirmingDeleteId', $gameServer->id)
            ->assertSet('deleteImpactAccountCount', 2)
            ->assertSee('Последствия удаления изменились')
            ->call('deleteServer')
            ->assertSet('confirmingDeleteId', null);

        $this->assertDatabaseMissing('game_servers', ['id' => $gameServer->id]);
    }

    public function test_delete_confirmation_buttons_use_a_non_reserved_livewire_action(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('confirmDelete', $loginServer->id)
            ->assertSee('wire:click="deleteServer"', false)
            ->assertDontSee('wire:click="delete"', false);

        Livewire::test(GameServerManager::class)
            ->call('confirmDelete', $gameServer->id)
            ->assertSee('wire:click="deleteServer"', false)
            ->assertDontSee('wire:click="delete"', false);
    }

    public function test_login_server_drawer_separates_network_settings_into_tabs(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('create')
            ->assertSet('activeTab', 'general')
            ->assertSee('Основное')
            ->assertSee('Сетевые настройки')
            ->assertSee('Подключение к базе данных')
            ->assertDontSee('Дополнительные сетевые настройки')
            ->call('setActiveTab', 'network')
            ->assertSet('activeTab', 'network')
            ->assertSee('Дополнительные сетевые настройки')
            ->assertSee('Адрес службы')
            ->assertSee('Порт службы')
            ->assertDontSee('Подключение к базе данных');
    }

    public function test_login_server_validation_opens_the_network_tab_for_service_errors(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        Livewire::test(LoginServerManager::class)
            ->call('create')
            ->set('name', 'Primary Login')
            ->set('driver', 'l2j_mobius')
            ->set('databaseHost', '127.0.0.1')
            ->set('databasePort', '3306')
            ->set('databaseName', 'l2jmobiusinterlude')
            ->set('databaseUsername', 'l2forge')
            ->set('databasePassword', 'SecretLoginPassword')
            ->set('databaseCharset', 'utf8mb4')
            ->set('servicePort', '70000')
            ->call('save')
            ->assertHasErrors(['servicePort'])
            ->assertSet('activeTab', 'network');
    }

    public function test_server_drawers_close_on_backdrop_pointer_press_instead_of_click_release(): void
    {
        $this->actingAs($this->createAdmin(), 'admin');

        foreach (['/admin/settings/login-server', '/admin/settings/game-server'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('wire:pointerdown.self="closeDrawer"', false)
                ->assertDontSee('wire:click.self="closeDrawer"', false);
        }
    }

    /** @return array<string,mixed> */
    private function loginServerValues(): array
    {
        return [
            'name' => 'Primary Login',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2jmobiusinterlude',
            'database_username' => 'l2forge',
            'database_password' => 'SecretLoginPassword',
            'database_charset' => 'utf8mb4',
        ];
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
}
