<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Servers\ServerDatabaseState;
use App\Services\Servers\ServerDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class ExternalDatabaseDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_refresh_and_view_safe_external_database_diagnostics(): void
    {
        $admin = Admin::factory()->create();

        // The clean-install migration creates one starter GameServer. This
        // scenario must exercise only the explicitly configured diagnostics pair.
        GameServer::query()->delete();
        LoginServer::query()->delete();

        $loginServer = LoginServer::factory()->create([
            'name' => 'Diagnostics Login',
            'driver' => ServerDriverRegistry::MOBIUS_DRIVER,
            'database_host' => 'secret-db.internal.example',
            'database_name' => 'secret_login_database',
            'database_username' => 'secret_database_user',
            'database_password' => 'SecretDatabasePassword',
        ]);
        $gameServer = GameServer::factory()
            ->for($loginServer, 'loginServer')
            ->create([
                'name' => 'Diagnostics Game',
                'chronicle' => 'Tales Untold',
                'driver' => ServerDriverRegistry::MOBIUS_DRIVER,
                'use_login_server_connection' => true,
            ]);

        $this->assertDatabaseCount('login_servers', 1);
        $this->assertDatabaseCount('game_servers', 1);
        $fake = new FakeExternalDatabaseConnectionTester;
        $fake->reports = [
            [
                'connected' => true,
                'compatible' => true,
                'server_version' => '10.11.8-MariaDB',
                'error' => null,
                'error_class' => null,
                'latency_ms' => 17,
                'checks' => [
                    $this->check('accounts'),
                    $this->check('account_data', required: false),
                    $this->check('accounts_ipauth', required: false, exists: false),
                ],
            ],
            [
                'connected' => true,
                'compatible' => true,
                'server_version' => '10.11.8-MariaDB',
                'error' => null,
                'error_class' => null,
                'latency_ms' => 17,
                'checks' => [
                    $this->check('characters', matched: ['reputation']),
                    $this->check('clan_data'),
                    $this->check('heroes', required: false),
                    $this->check('castle', required: false, exists: false),
                ],
            ],
        ];
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.settings.system.external-databases.refresh'))
            ->assertRedirect(route('admin.settings.system'))
            ->assertSessionHas('status', 'Диагностика внешних баз обновлена: успешно — 2, требуют внимания — 0.');

        $loginServer->refresh();
        $gameServer->refresh();
        $this->assertSame('configured', $loginServer->database_status);
        $this->assertSame('mobius_interlude_plus', $loginServer->database_schema_profile);
        $this->assertSame(17, $loginServer->database_latency_ms);
        $this->assertNotNull($loginServer->database_last_success_at);
        $this->assertContains('account_creation', $loginServer->database_capabilities ?? []);
        $this->assertSame('configured', $gameServer->database_status);
        $this->assertSame('mobius_modern', $gameServer->database_schema_profile);
        $this->assertContains('heroes', $gameServer->database_capabilities ?? []);
        $this->assertNotContains('castles', $gameServer->database_capabilities ?? []);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.settings.system'))
            ->assertOk()
            ->assertSee('Диагностика внешних баз')
            ->assertSee('Diagnostics Login')
            ->assertSee('Diagnostics Game')
            ->assertSee('Mobius modern')
            ->assertSee('17 мс')
            ->assertSee('characters')
            ->assertSee('Необязательная таблица отсутствует')
            ->assertSee('Создание аккаунтов')
            ->assertSee('Герои')
            ->assertDontSee('secret-db.internal.example')
            ->assertDontSee('secret_login_database')
            ->assertDontSee('secret_database_user')
            ->assertDontSee('SecretDatabasePassword');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'external_databases.diagnostics_refreshed',
            'result' => 'success',
        ]);
    }

    public function test_failed_check_keeps_last_successful_schema_snapshot_and_records_only_error_class(): void
    {
        $server = LoginServer::factory()->create();
        $state = app(ServerDatabaseState::class);
        $state->apply($server, [
            'connected' => true,
            'driver_ready' => true,
            'compatible' => true,
            'latency_ms' => 8,
            'schema_profile' => 'mobius_interlude_plus',
            'capabilities' => ['account_lookup'],
            'checks' => [$this->check('accounts')],
        ]);
        $lastSuccessAt = $server->fresh()?->database_last_success_at;

        $server->refresh();
        $state->apply($server, [
            'connected' => false,
            'driver_ready' => true,
            'compatible' => false,
            'error_class' => 'PDOException',
            'latency_ms' => 3001,
            'checks' => [],
        ]);

        $server->refresh();
        $this->assertSame('not_configured', $server->database_status);
        $this->assertSame('connection_failed', $server->database_error);
        $this->assertSame('PDOException', $server->database_last_error_class);
        $this->assertNotNull($server->database_last_error_at);
        $this->assertTrue($lastSuccessAt?->equalTo($server->database_last_success_at) ?? false);
        $this->assertSame(8, $server->database_latency_ms);
        $this->assertSame('mobius_interlude_plus', $server->database_schema_profile);
        $this->assertSame(['account_lookup'], $server->database_capabilities);
        $this->assertSame('accounts', $server->database_table_checks[0]['table'] ?? null);
    }

    /** @return array{table:string,required:bool,table_exists:bool,missing_columns:list<string>,matched_any_columns:list<string>} */
    private function check(
        string $table,
        bool $required = true,
        bool $exists = true,
        array $missing = [],
        array $matched = [],
    ): array {
        return [
            'table' => $table,
            'required' => $required,
            'table_exists' => $exists,
            'missing_columns' => $missing,
            'matched_any_columns' => $matched,
        ];
    }
}
