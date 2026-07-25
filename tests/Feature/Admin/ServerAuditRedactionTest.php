<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\GameServer;
use App\Services\Servers\GameServerAdministration;
use App\Services\Servers\LoginServerAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class ServerAuditRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_audit_logs_do_not_store_infrastructure_credentials(): void
    {
        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
        $this->actingAs($this->createAdmin(), 'admin');

        $loginServers = app(LoginServerAdministration::class);
        $loginResult = $loginServers->save(null, [
            'name' => 'Primary Login',
            'driver' => 'l2j_mobius',
            'database_host' => 'private-login-db.internal',
            'database_port' => 3306,
            'database_name' => 'private_login_schema',
            'database_username' => 'private_login_user',
            'database_password' => 'PrivateLoginPassword',
            'database_charset' => 'utf8mb4',
            'service_host' => 'private-login-service.internal',
            'service_port' => 2106,
        ]);
        $loginServer = $loginResult['server'];

        $loginServers->save($loginServer, [
            'name' => 'Primary Login',
            'driver' => 'l2j_mobius',
            'database_host' => 'changed-login-db.internal',
            'database_port' => 3307,
            'database_name' => 'changed_login_schema',
            'database_username' => 'changed_login_user',
            'database_password' => 'ChangedLoginPassword',
            'database_charset' => 'utf8',
            'service_host' => 'changed-login-service.internal',
            'service_port' => 2206,
        ]);

        $gameServer = GameServer::query()->firstOrFail();
        app(GameServerAdministration::class)->save(
            $gameServer,
            [
                'name' => 'Interlude x5',
                'rates' => 'x5',
                'chronicle' => 'Interlude',
                'mode' => 'PvP',
                'translations' => ['ru' => 'Interlude x5'],
                'maintenance_enabled' => false,
                'maintenance_messages' => [],
            ],
            GameServerAdministration::CONNECTION_CONNECT,
            [
                'login_server_id' => $loginServer->id,
                'driver' => 'l2j_mobius_ct0_interlude',
                'use_login_server_connection' => false,
                'database_host' => 'private-game-db.internal',
                'database_port' => 3308,
                'database_name' => 'private_game_schema',
                'database_username' => 'private_game_user',
                'database_password' => 'PrivateGamePassword',
                'database_charset' => 'utf8mb4',
                'service_host' => 'private-game-service.internal',
                'service_port' => 7777,
            ],
        );

        $serialized = json_encode(
            AuditLog::query()
                ->whereIn('action', [
                    'login_server.created',
                    'login_server.updated',
                    'game_server.updated',
                ])
                ->pluck('details')
                ->all(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $this->assertIsString($serialized);

        foreach ([
            'private-login-db.internal',
            'private_login_schema',
            'private_login_user',
            'PrivateLoginPassword',
            'changed-login-db.internal',
            'changed_login_schema',
            'changed_login_user',
            'ChangedLoginPassword',
            'private-login-service.internal',
            'changed-login-service.internal',
            'private-game-db.internal',
            'private_game_schema',
            'private_game_user',
            'PrivateGamePassword',
            'private-game-service.internal',
        ] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }

        $loginUpdate = AuditLog::query()->where('action', 'login_server.updated')->firstOrFail();
        $this->assertTrue((bool) data_get($loginUpdate->details, 'connection_changes.database_address_changed'));
        $this->assertTrue((bool) data_get($loginUpdate->details, 'connection_changes.database_authentication_changed'));
        $this->assertTrue((bool) data_get($loginUpdate->details, 'connection_changes.service_address_changed'));
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Audit Admin',
            'email' => 'audit-admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }
}
