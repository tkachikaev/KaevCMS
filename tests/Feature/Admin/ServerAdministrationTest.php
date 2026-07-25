<?php

namespace Tests\Feature\Admin;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\UserGameAccount;
use App\Services\Servers\GameServerAdministration;
use App\Services\Servers\LoginServerAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

class ServerAdministrationTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ExternalDatabaseConnectionTester::class, new FakeExternalDatabaseConnectionTester);
    }

    public function test_login_server_update_preserves_password_and_optional_service_endpoint(): void
    {
        $this->clearServerFixtures();
        $server = LoginServer::factory()->online()->create([
            'database_password' => 'ExistingPassword',
            'service_host' => 'login.example.test',
            'service_port' => 2106,
        ]);

        app(LoginServerAdministration::class)->save($server, [
            'name' => 'Updated LoginServer',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.2',
            'database_port' => 3306,
            'database_name' => 'l2j_updated',
            'database_username' => 'cms',
            'database_password' => '',
            'database_charset' => 'utf8mb4',
        ]);

        $server->refresh();
        $this->assertSame('ExistingPassword', $server->databasePassword());
        $this->assertSame('login.example.test', $server->service_host);
        $this->assertSame(2106, $server->service_port);
        $this->assertSame('unknown', $server->monitor_status);
        $this->assertNull($server->monitor_checked_at);
    }

    public function test_internal_database_check_failure_keeps_login_server_status_pending(): void
    {
        $this->clearServerFixtures();
        $this->app->instance(ExternalDatabaseConnectionTester::class, new class implements ExternalDatabaseConnectionTester
        {
            public function test(array $connection, array $requirements, bool $driverReady): array
            {
                throw new RuntimeException('Temporary tester failure');
            }
        });

        $result = app(LoginServerAdministration::class)->save(null, [
            'name' => 'Pending LoginServer',
            'driver' => 'l2j_mobius',
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'l2j_login',
            'database_username' => 'cms',
            'database_password' => 'SecretPassword',
            'database_charset' => 'utf8mb4',
        ]);

        $server = $result['server']->fresh();
        $this->assertInstanceOf(LoginServer::class, $server);
        $this->assertSame('unknown', $server->database_status);
        $this->assertSame('check_failed', $server->database_error);
        $this->assertNull($server->database_checked_at);
    }

    public function test_game_server_profile_update_preserves_existing_connection(): void
    {
        [$loginServer, $gameServer] = $this->freshMobiusServerPair();

        app(GameServerAdministration::class)->save($gameServer, [
            'name' => 'Interlude x25',
            'rates' => 'x25',
            'chronicle' => 'Interlude',
            'mode' => 'PvP',
            'translations' => ['ru' => 'Interlude x25'],
        ]);

        $gameServer->refresh();
        $this->assertSame('Interlude x25', $gameServer->name);
        $this->assertSame($loginServer->id, $gameServer->login_server_id);
        $this->assertSame('l2j_mobius_ct0_interlude', $gameServer->driver);
        $this->assertTrue($gameServer->use_login_server_connection);
    }

    public function test_moving_game_server_reassigns_old_login_accounts_to_its_replacement(): void
    {
        [$oldLoginServer, $movingServer] = $this->freshMobiusServerPair();
        $replacement = GameServer::factory()->for($oldLoginServer)->create([
            'name' => 'Replacement',
            'sort_order' => 2,
        ]);
        $newLoginServer = LoginServer::factory()->create(['name' => 'New LoginServer']);
        $account = UserGameAccount::factory()->registeredOn($movingServer)->create();

        app(GameServerAdministration::class)->updateConnection($movingServer, [
            'login_server_id' => $newLoginServer->id,
            'driver' => 'l2j_mobius_ct0_interlude',
            'use_login_server_connection' => true,
            'database_password' => '',
        ]);

        $movingServer->refresh();
        $account->refresh();
        $this->assertSame($newLoginServer->id, $movingServer->login_server_id);
        $this->assertSame($oldLoginServer->id, $account->login_server_id);
        $this->assertSame($replacement->id, $account->registration_game_server_id);
    }
}
