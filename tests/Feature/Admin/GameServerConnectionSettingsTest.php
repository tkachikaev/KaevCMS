<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Models\LoginServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class GameServerConnectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
    }

    public function test_game_server_connection_requires_authentication(): void
    {
        $gameServer = GameServer::query()->firstOrFail();

        $this->post(route('admin.settings.game-server.connection', $gameServer), [])
            ->assertRedirect(route('admin.login'));
    }

    public function test_game_server_page_contains_connection_drivers_and_login_server_selection(): void
    {
        LoginServer::query()->create($this->loginServerValues());

        $admin = $this->createAdmin();
        $gameServer = GameServer::query()->firstOrFail();
        $this->actingAs($admin, 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $gameServer->id)
            ->set('connectionEnabled', true)
            ->assertSee('Подключение к базе данных')
            ->assertSee('L2J Mobius — все хроники')
            ->assertSee('RUSaCis')
            ->assertSee('Использовать параметры базы выбранного LoginServer');
    }

    public function test_game_server_can_use_selected_login_server_database_connection(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '1',
            ])
            ->assertRedirect(route('admin.settings.game-server'))
            ->assertSessionHas('status', 'Подключение к базе GameServer сохранено.');

        $gameServer->refresh();
        $this->assertSame($loginServer->id, $gameServer->login_server_id);
        $this->assertSame('l2j_mobius_ct0_interlude', $gameServer->driver);
        $this->assertTrue($gameServer->use_login_server_connection);
        $this->assertNull($gameServer->database_host);
        $this->assertTrue($gameServer->connectionConfigured());
    }

    public function test_game_server_can_store_separate_encrypted_database_credentials(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '0',
                'database_host' => '10.10.10.20',
                'database_port' => 3307,
                'database_name' => 'interlude_game',
                'database_username' => 'game_cms',
                'database_password' => 'GameDatabasePassword',
                'database_charset' => 'utf8',
            ])
            ->assertRedirect(route('admin.settings.game-server'));

        $gameServer->refresh();
        $this->assertFalse($gameServer->use_login_server_connection);
        $this->assertSame('10.10.10.20', $gameServer->database_host);
        $this->assertSame('GameDatabasePassword', $gameServer->databasePassword());
        $this->assertNotSame('GameDatabasePassword', $gameServer->getRawOriginal('database_password'));
    }

    public function test_separate_game_database_requires_connection_fields(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->from('/admin/settings/game-server')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '0',
            ])
            ->assertRedirect('/admin/settings/game-server')
            ->assertSessionHasErrors([
                'database_host',
                'database_port',
                'database_name',
                'database_username',
            ]);
    }

    public function test_empty_game_database_password_keeps_existing_secret(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update([
            'login_server_id' => $loginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => false,
            'database_host' => '10.10.10.20',
            'database_port' => 3306,
            'database_name' => 'interlude_game',
            'database_username' => 'game_cms',
            'database_password' => 'ExistingGameSecret',
            'database_charset' => 'utf8mb4',
        ]);
        $before = $gameServer->getRawOriginal('database_password');

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '0',
                'database_host' => '10.10.10.21',
                'database_port' => 3306,
                'database_name' => 'interlude_game',
                'database_username' => 'game_cms',
                'database_password' => '',
                'database_charset' => 'utf8mb4',
            ])
            ->assertRedirect(route('admin.settings.game-server'));

        $gameServer->refresh();
        $this->assertSame('ExistingGameSecret', $gameServer->databasePassword());
        $this->assertSame($before, $gameServer->getRawOriginal('database_password'));
        $this->assertSame('10.10.10.21', $gameServer->database_host);
    }

    public function test_game_database_password_is_not_rendered_or_written_to_audit(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '0',
                'database_host' => '10.10.10.20',
                'database_port' => 3306,
                'database_name' => 'interlude_game',
                'database_username' => 'game_cms',
                'database_password' => 'NeverExposeThisPassword',
                'database_charset' => 'utf8mb4',
            ])
            ->assertRedirect(route('admin.settings.game-server'));

        $this->actingAs(Admin::query()->where('email', 'admin@example.com')->firstOrFail(), 'admin')
            ->get(route('admin.settings.game-server'))
            ->assertOk()
            ->assertDontSee('NeverExposeThisPassword')
            ->assertSee('Пароль базы данных сохранён.');

        $audit = AuditLog::query()->where('action', 'game_server.connection_updated')->firstOrFail();
        $this->assertStringNotContainsString('NeverExposeThisPassword', json_encode($audit->details, JSON_THROW_ON_ERROR));
    }

    public function test_rusacis_game_driver_is_saved_as_placeholder(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'rusacis',
                'use_login_server_connection' => '1',
            ])
            ->assertRedirect(route('admin.settings.game-server'));

        $this->assertDatabaseHas('game_servers', [
            'id' => $gameServer->id,
            'driver' => 'rusacis',
            'login_server_id' => $loginServer->id,
        ]);
    }

    public function test_unknown_game_driver_is_rejected(): void
    {
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->from('/admin/settings/game-server')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'save',
                'login_server_id' => $loginServer->id,
                'driver' => 'unknown-driver',
                'use_login_server_connection' => '1',
            ])
            ->assertRedirect('/admin/settings/game-server')
            ->assertSessionHasErrors('driver');

        $this->assertNull($gameServer->fresh()->driver);
    }

    public function test_game_connection_test_uses_login_server_credentials_without_saving(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $loginServer = LoginServer::query()->create($this->loginServerValues());
        $gameServer = GameServer::query()->firstOrFail();

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.game-server.connection', $gameServer), [
                'connection_action' => 'test',
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => '1',
            ])
            ->assertRedirect(route('admin.settings.game-server').'#game-server-'.$gameServer->id.'-connection')
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['context'] === 'game-'.$gameServer->id
                && $report['connected'] === true
                && $report['driver'] === 'l2j_mobius_ct0_interlude'
            );

        $this->assertNull($gameServer->fresh()->driver);
        $this->assertSame('l2jmobiusinterlude', $fake->connection['database'] ?? null);
        $this->assertSame('SecretLoginPassword', $fake->connection['password'] ?? null);
        $this->assertSame('characters', $fake->requirements[0]['table'] ?? null);
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
