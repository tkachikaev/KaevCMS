<?php

namespace Tests\Feature\UniversalDrivers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Contracts\GameServerDatabaseGateway;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\LoginServerDriverResolver;
use App\Services\GameAccounts\UniversalLoginDriver;
use App\Services\Servers\MySqlGameServerOnlineCounter;
use App\Services\Servers\ServerDriverRegistry;
use App\Services\Servers\UniversalDriverActivation;
use App\Support\UniversalDrivers\ConnectionProfileSnapshots;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ConnectionActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_custom_profiles_without_replacing_legacy_mobius_and_rusacis_descriptors(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $base = $catalog->find('login', 'rusacis')['profile'];
        $base['id'] = 'custom-login';
        $base['name'] = 'Custom Login';
        $catalog->create($base);

        $registry = app(ServerDriverRegistry::class);
        $this->assertSame('Custom Login', $registry->loginDriver('custom-login')['label']);
        $this->assertSame('rusacis_interlude', $registry->loginDriver('rusacis')['schema_profile']);
        $this->assertSame($base['config']['requirements'], $registry->loginDriver('custom-login')['requirements']);
    }

    public function test_failed_application_keeps_last_verified_snapshot_and_legacy_driver_is_not_switched_implicitly(): void
    {
        $probe = new class implements ExternalDatabaseConnectionTester
        {
            public bool $allowed = true;

            public function test(array $connection, array $requirements, bool $driverReady): array
            {
                return [
                    'connected' => $this->allowed,
                    'compatible' => $this->allowed,
                    'server_version' => 'fixture',
                    'error' => $this->allowed ? null : 'fixture_offline',
                    'error_class' => null,
                    'latency_ms' => 1,
                    'checks' => [],
                ];
            }
        };
        $this->app->instance(ExternalDatabaseConnectionTester::class, $probe);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        $resolver = app(LoginServerDriverResolver::class);
        $this->assertNotInstanceOf(UniversalLoginDriver::class, $resolver->resolve($server));
        app(UniversalDriverActivation::class)->apply($server);
        $snapshots = app(ConnectionProfileSnapshots::class);
        $this->assertNotNull($snapshots->get($server));
        $this->assertInstanceOf(UniversalLoginDriver::class, $resolver->resolve($server));

        $original = DB::table('universal_driver_connection_snapshots')
            ->where('connection_type', 'login')->where('connection_id', $server->id)->value('profile_sha256');
        $edited = app(DriverProfileCatalog::class)->find('login', 'rusacis')['profile'];
        $edited['name'] = 'Candidate that cannot connect';
        app(DriverProfileCatalog::class)->save($edited);
        $probe->allowed = false;
        try {
            app(UniversalDriverActivation::class)->apply($server);
            $this->fail('Incompatible candidate was applied.');
        } catch (ProfileValidationException) {
            $this->assertSame($original, DB::table('universal_driver_connection_snapshots')
                ->where('connection_type', 'login')->where('connection_id', $server->id)->value('profile_sha256'));
        }
        $this->assertNotSame('Candidate that cannot connect', $snapshots->get($server)['name']);
    }

    public function test_stale_snapshot_fails_closed_when_connection_credentials_change_outside_admin(): void
    {
        $probe = new class implements ExternalDatabaseConnectionTester
        {
            public function test(array $connection, array $requirements, bool $driverReady): array
            {
                return ['connected' => true, 'compatible' => true, 'driver_ready' => true,
                    'server_version' => 'fixture', 'error' => null, 'error_class' => null,
                    'latency_ms' => 1, 'checks' => []];
            }
        };
        $this->app->instance(ExternalDatabaseConnectionTester::class, $probe);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        app(UniversalDriverActivation::class)->apply($server);
        $server->update(['database_name' => 'another_game_db']);
        $this->expectException(RuntimeException::class);
        app(ConnectionProfileSnapshots::class)->get($server);
    }

    public function test_unverified_custom_login_profile_is_not_executable(): void
    {
        $profile = app(DriverProfileCatalog::class)->find('login', 'rusacis')['profile'];
        $profile['id'] = 'custom-unverified';
        $profile['name'] = 'Custom unverified';
        app(DriverProfileCatalog::class)->create($profile);
        $server = LoginServer::factory()->create(['driver' => 'custom-unverified']);
        $this->assertFalse(app(LoginServerDriverResolver::class)->supports($server));
        $this->expectException(RuntimeException::class);
        app(LoginServerDriverResolver::class)->resolve($server);
    }

    public function test_unapplied_custom_game_profile_cannot_query_the_online_counter(): void
    {
        $catalog = app(DriverProfileCatalog::class);
        $profile = $catalog->find('game', 'rusacis')['profile'];
        $profile['id'] = 'unapplied-online';
        $profile['name'] = 'Unapplied online';
        $catalog->create($profile);
        $server = GameServer::factory()->create(['driver' => $profile['id']]);
        $database = Mockery::mock(GameServerDatabaseGateway::class);
        $database->shouldNotReceive('run');
        $this->app->instance(GameServerDatabaseGateway::class, $database);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Apply and verify');
        app(MySqlGameServerOnlineCounter::class)->count($server);
    }
}
