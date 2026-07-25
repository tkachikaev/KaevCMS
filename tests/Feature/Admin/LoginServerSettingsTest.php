<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class LoginServerSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
    }

    public function test_login_server_settings_require_authentication(): void
    {
        $this->get('/admin/settings/login-server')->assertRedirect(route('admin.login'));
        $this->post('/admin/settings/login-server', [])->assertRedirect(route('admin.login'));
    }

    public function test_administrator_can_open_login_server_settings_and_see_drivers(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->get('/admin/settings/login-server')
            ->assertOk()
            ->assertSee('Логин серверы')
            ->assertSee('L2J Mobius — Interlude и новее')
            ->assertSee('L2J Mobius Legacy — C1/C4')
            ->assertSee('RUSaCis')
            ->assertSee('заглушка')
            ->assertSee('Хост базы данных')
            ->assertSee('Кодировка базы данных');
    }

    public function test_administrator_can_save_login_server_with_encrypted_password(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.store'), $this->payload())
            ->assertRedirect(route('admin.settings.login-server'))
            ->assertSessionHas('status', 'LoginServer добавлен.');

        $server = LoginServer::query()->firstOrFail();

        $this->assertSame('Primary Login', $server->name);
        $this->assertSame('SecretDatabasePassword', $server->databasePassword());
        $this->assertNotSame('SecretDatabasePassword', $server->getRawOriginal('database_password'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_server.created',
            'target_type' => 'login_server',
        ]);
        $audit = AuditLog::query()->where('action', 'login_server.created')->firstOrFail();
        $this->assertStringNotContainsString('SecretDatabasePassword', json_encode($audit->details, JSON_THROW_ON_ERROR));
    }

    public function test_empty_password_keeps_existing_login_server_password(): void
    {
        $server = LoginServer::query()->create($this->modelValues());
        $before = $server->getRawOriginal('database_password');

        $payload = $this->payload();
        $payload['name'] = 'Updated Login';
        $payload['database_password'] = '';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.update', $server), $payload)
            ->assertRedirect(route('admin.settings.login-server'));

        $server->refresh();
        $this->assertSame('Updated Login', $server->name);
        $this->assertSame('SecretDatabasePassword', $server->databasePassword());
        $this->assertSame($before, $server->getRawOriginal('database_password'));
    }

    public function test_connection_can_be_checked_without_saving_login_server(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $payload = $this->payload();
        $payload['connection_action'] = 'test';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect(route('admin.settings.login-server').'#login-server-create')
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['context'] === 'login-create'
                && $report['connected'] === true
                && $report['driver'] === 'l2j_mobius'
            );

        $this->assertDatabaseCount('login_servers', 0);
        $this->assertSame('127.0.0.1', $fake->connection['host'] ?? null);
        $this->assertSame('SecretDatabasePassword', $fake->connection['password'] ?? null);
        $this->assertTrue($fake->driverReady);
        $this->assertSame('accounts', $fake->requirements[0]['table'] ?? null);
    }

    public function test_legacy_mobius_connection_check_requires_only_accounts_table(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $payload = $this->payload();
        $payload['connection_action'] = 'test';
        $payload['driver'] = 'l2j_mobius_legacy';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect(route('admin.settings.login-server').'#login-server-create')
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['driver'] === 'l2j_mobius_legacy'
                && $report['driver_ready'] === true
                && $report['driver_label'] === 'L2J Mobius Legacy — C1/C4'
            );

        $this->assertTrue($fake->driverReady);
        $this->assertSame([
            [
                'table' => 'accounts',
                'columns' => ['login', 'password', 'email', 'created_time', 'lastactive', 'accessLevel', 'lastIP', 'lastServer'],
                'required' => true,
            ],
        ], $fake->requirements);
    }

    public function test_failed_connection_test_is_reported_and_audited_without_saving(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->report = [
            'connected' => false,
            'compatible' => false,
            'server_version' => null,
            'error' => 'connection_failed',
            'checks' => [],
        ];
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $payload = $this->payload();
        $payload['connection_action'] = 'test';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect(route('admin.settings.login-server').'#login-server-create')
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['connected'] === false
                && $report['compatible'] === false
                && $report['error'] === 'connection_failed'
            );

        $this->assertDatabaseCount('login_servers', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_server.connection_tested',
            'result' => 'failed',
        ]);
    }

    public function test_existing_login_server_connection_test_returns_to_its_card(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $server = LoginServer::query()->create($this->modelValues());
        $payload = $this->payload();
        $payload['connection_action'] = 'test';
        $payload['database_password'] = '';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.update', $server), $payload)
            ->assertRedirect(route('admin.settings.login-server').'#login-server-'.$server->id)
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['context'] === 'login-'.$server->id);
    }

    public function test_saved_database_password_is_never_rendered_in_settings_page(): void
    {
        LoginServer::query()->create($this->modelValues());

        $this->actingAs($this->createAdmin(), 'admin')
            ->get('/admin/settings/login-server')
            ->assertOk()
            ->assertDontSee('SecretDatabasePassword')
            ->assertSee('Пароль базы данных сохранён.');
    }

    public function test_unknown_login_driver_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['driver'] = 'unknown-driver';

        $this->actingAs($this->createAdmin(), 'admin')
            ->from('/admin/settings/login-server')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect('/admin/settings/login-server')
            ->assertSessionHasErrors('driver');

        $this->assertDatabaseCount('login_servers', 0);
    }

    public function test_rusacis_placeholder_checks_connection_without_schema_requirements(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $payload = $this->payload();
        $payload['connection_action'] = 'test';
        $payload['driver'] = 'rusacis';

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect(route('admin.settings.login-server').'#login-server-create')
            ->assertSessionHas('database_connection_report', fn (array $report): bool => $report['driver'] === 'rusacis'
                && $report['driver_ready'] === false
                && $report['compatible'] === null
            );

        $this->assertFalse($fake->driverReady);
        $this->assertSame([], $fake->requirements);
    }

    public function test_database_password_is_not_flashed_after_validation_error(): void
    {
        $payload = $this->payload();
        $payload['name'] = '';

        $this->actingAs($this->createAdmin(), 'admin')
            ->from('/admin/settings/login-server')
            ->post(route('admin.settings.login-server.store'), $payload)
            ->assertRedirect('/admin/settings/login-server')
            ->assertSessionHasErrors('name')
            ->assertSessionMissingInput('database_password');
    }

    public function test_unused_login_server_can_be_deleted(): void
    {
        $loginServer = LoginServer::query()->create($this->modelValues());

        $this->actingAs($this->createAdmin(), 'admin')
            ->delete(route('admin.settings.login-server.destroy', $loginServer))
            ->assertRedirect(route('admin.settings.login-server'))
            ->assertSessionHas('status', 'LoginServer «Primary Login» удалён.');

        $this->assertDatabaseMissing('login_servers', ['id' => $loginServer->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_server.deleted',
            'target_name' => 'Primary Login',
        ]);
    }

    public function test_login_server_used_by_game_server_cannot_be_deleted(): void
    {
        $loginServer = LoginServer::query()->create($this->modelValues());
        $gameServer = GameServer::query()->firstOrFail();
        $gameServer->update(['login_server_id' => $loginServer->id]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->delete(route('admin.settings.login-server.destroy', $loginServer))
            ->assertSessionHasErrors('login_server');

        $this->assertDatabaseHas('login_servers', ['id' => $loginServer->id]);
    }

    public function test_login_server_used_by_player_account_cannot_be_deleted(): void
    {
        $loginServer = LoginServer::query()->create($this->modelValues());
        $user = User::query()->create([
            'name' => 'Player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('CorrectPassword123'),
        ]);
        UserGameAccount::query()->create([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => null,
            'game_login' => 'player01',
            'normalized_login' => 'player01',
            'created_via_cms' => true,
        ]);

        $this->actingAs($this->createAdmin(), 'admin')
            ->delete(route('admin.settings.login-server.destroy', $loginServer))
            ->assertSessionHasErrors('login_server');

        $this->assertDatabaseHas('login_servers', ['id' => $loginServer->id]);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'connection_action' => 'save',
            'name' => 'Primary Login',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2jmobiusinterlude',
            'database_username' => 'l2forge',
            'database_password' => 'SecretDatabasePassword',
            'database_charset' => 'utf8mb4',
        ];
    }

    /** @return array<string,mixed> */
    private function modelValues(): array
    {
        $values = $this->payload();
        unset($values['connection_action']);

        return $values;
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
