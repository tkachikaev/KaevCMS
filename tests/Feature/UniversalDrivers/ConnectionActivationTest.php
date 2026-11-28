<?php

namespace Tests\Feature\UniversalDrivers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Contracts\GameServerDatabaseGateway;
use App\Exceptions\ExternalDatabaseDriverUnavailable;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\GameAccounts\LoginServerDriverResolver;
use App\Services\GameWorld\GameWorldDriverResolver;
use App\Services\Servers\GameServerAdministration;
use App\Services\Servers\LoginServerAdministration;
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
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
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

    public function test_failed_application_keeps_last_verified_snapshot(): void
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
        $snapshots = app(ConnectionProfileSnapshots::class);
        $this->assertNull($snapshots->get($server));
        $this->assertStringStartsWith('$2a$10$', $resolver->resolve($server)->encodePassword('Password123'));
        app(UniversalDriverActivation::class)->apply($server);
        $this->assertNotNull($snapshots->get($server));

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
        $probe = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $probe);
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        app(UniversalDriverActivation::class)->apply($server);
        $server->update(['database_name' => 'another_game_db']);
        $this->expectException(RuntimeException::class);
        app(ConnectionProfileSnapshots::class)->get($server);
    }

    public function test_saving_same_game_driver_keeps_verified_snapshot_and_changed_target_requires_verification(): void
    {
        $probe = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $probe);
        $server = GameServer::factory()->create(['driver' => 'rusacis', 'use_login_server_connection' => true]);
        $snapshots = app(ConnectionProfileSnapshots::class);
        $snapshots->verifyAndRecord($server, 'rusacis', static fn (): bool => true);
        $original = $snapshots->get($server);
        $values = ['driver' => 'rusacis', 'login_server_id' => $server->login_server_id, 'use_login_server_connection' => true];
        $admin = app(GameServerAdministration::class);

        $server = $admin->updateConnection($server, $values);
        $this->assertSame($original, $snapshots->get($server));

        $otherLogin = LoginServer::factory()->create();
        $server = $admin->updateConnection($server, array_replace($values, ['login_server_id' => $otherLogin->id]));
        $this->expectException(ExternalDatabaseDriverUnavailable::class);
        $snapshots->get($server);
    }

    public function test_login_credentials_change_blocks_inherited_json_until_reverified_without_affecting_separate_database(): void
    {
        $probe = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $probe);
        $login = LoginServer::factory()->create(['driver' => 'rusacis']);
        $inherited = GameServer::factory()->create(['driver' => 'rusacis', 'login_server_id' => $login->id]);
        $separate = GameServer::factory()->create([
            'driver' => 'rusacis', 'login_server_id' => $login->id, 'use_login_server_connection' => false,
            'database_host' => '127.0.0.1', 'database_port' => 3306, 'database_name' => 'separate_world',
            'database_username' => 'cms', 'database_password' => 'fixture', 'database_charset' => 'utf8mb4',
        ]);
        $activation = app(UniversalDriverActivation::class);
        $snapshots = app(ConnectionProfileSnapshots::class);
        foreach ([$login, $inherited, $separate] as $server) {
            $activation->apply($server);
        }
        $original = $snapshots->get($inherited);
        $separateProfile = $snapshots->get($separate);
        $values = [
            'name' => 'Renamed login', 'driver' => 'rusacis',
            'database_host' => $login->database_host, 'database_port' => $login->database_port,
            'database_name' => $login->database_name, 'database_username' => $login->database_username,
            'database_charset' => $login->database_charset, 'database_password' => '',
        ];
        $admin = app(LoginServerAdministration::class);
        $login = $admin->save($login, $values)['server'];
        $this->assertSame($original, $snapshots->get($inherited->refresh()));
        $this->assertTrue(app(LoginServerDriverResolver::class)->supports($login));
        $stale = $inherited->fresh();
        $stale->load('loginServer');

        $login = $admin->save($login, array_replace($values, ['database_name' => 'new_login_db']))['server'];
        $inherited->refresh();
        $separate->refresh();
        $this->assertFalse(app(LoginServerDriverResolver::class)->supports($login));
        $this->assertSame('driver_verification_required', $login->database_error);
        try {
            app(GameWorldDriverResolver::class)->resolve($inherited);
            $this->fail('Stale inherited profile resolved to a working driver.');
        } catch (ExternalDatabaseDriverUnavailable) {
            // Expected: no implicit fallback to the legacy PHP driver.
        }
        $calls = $probe->calls;
        $report = app(GameServerAdministration::class)->testStored($inherited);
        $this->assertFalse($report['compatible']);
        $this->assertSame('driver_verification_required', $report['error']);
        $this->assertSame($calls, $probe->calls);
        $this->assertSame('driver_verification_required', $inherited->refresh()->database_error);
        $this->assertSame($separateProfile, $snapshots->get($separate));
        $this->assertTrue($separate->databaseVerified());

        $activation->apply($login);
        $activation->apply($inherited);
        $this->assertTrue(app(LoginServerDriverResolver::class)->supports($login));
        $this->assertNotNull($snapshots->get($inherited->refresh()));
        $this->assertTrue($inherited->databaseVerified());

        // Re-verification must not authorize an old, already loaded DB target.
        $this->expectException(ExternalDatabaseDriverUnavailable::class);
        app(GameServerDatabaseGateway::class)->run($stale, static fn (): never => throw new RuntimeException('Stale connection reached external operation.'));
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
