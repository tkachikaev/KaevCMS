<?php

namespace Tests\Feature;

use App\Exceptions\GameAccountCreationException;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use App\Services\GameAccounts\GameAccountProvisioner;
use App\Services\GameAccounts\LoginServerDriverResolver;
use App\Services\GameAccounts\MobiusLoginDriver;
use App\Services\GameAccounts\RusacisLoginDriver;
use App\Support\GameAccounts\ExternalGameAccountState;
use App\Support\GameAccounts\PreparedGameAccount;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LoginServerDriversTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::purge('login_driver_test');
        parent::tearDown();
    }

    public function test_rusacis_round_trip_uses_supplied_sql_without_email_and_preserves_server_defaults(): void
    {
        config()->set('database.connections.login_driver_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database = DB::connection('login_driver_test');
        $database->unprepared(file_get_contents(base_path('tests/Fixtures/rusacis/accounts.sql')));
        $driver = app(RusacisLoginDriver::class);
        $hash = $driver->encodePassword('DriverPassword123');
        $proof = new PreparedGameAccount('Driver01', $hash, 'cms-only@example.test');
        $this->assertSame(ExternalGameAccountState::Missing, $driver->inspect($database, $proof));
        $driver->create($database, $proof);
        $this->assertSame(ExternalGameAccountState::Matching, $driver->inspect($database, $proof));
        $this->assertSame(0, (int) $database->table('accounts')->value('last_active'));
        $this->assertSame(1, (int) $database->table('accounts')->value('last_server'));
        $this->assertNull($driver->summary($database, 'Missing'));
        $this->assertSame('active', $driver->summary($database, 'Driver01')['status']);
        $database->table('accounts')->update(['access_level' => -1]);
        $this->assertSame('blocked', $driver->summary($database, 'Driver01')['status']);
        $this->assertTrue($driver->changePassword($database, 'Driver01', 'ChangedPassword123'));
        $this->assertTrue(password_verify('ChangedPassword123', $database->table('accounts')->value('password')));
        $this->assertSame(ExternalGameAccountState::Conflict, $driver->inspect($database, $proof));
        $this->assertFalse($driver->changePassword($database, 'Missing', 'ChangedPassword123'));
    }

    public function test_mobius_keeps_sha1_email_lookup_and_summary_contract(): void
    {
        config()->set('database.connections.login_driver_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database = DB::connection('login_driver_test');
        $database->getSchemaBuilder()->create('accounts', function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->string('password');
            $table->string('email');
            $table->string('created_time')->default('2026-10-15 00:00:00');
            $table->bigInteger('lastactive')->default(0);
            $table->integer('accessLevel')->default(0);
        });
        $driver = app(MobiusLoginDriver::class);
        $proof = new PreparedGameAccount('Mobius01', $driver->encodePassword('Password123'), 'player@example.test');
        $this->assertSame(base64_encode(sha1('Password123', true)), $proof->credential);
        $driver->create($database, $proof);
        $this->assertSame(ExternalGameAccountState::Matching, $driver->inspect($database, $proof));
        $this->assertSame('2026-10-15 00:00:00', $driver->summary($database, 'Mobius01')['created_at']);
        $database->table('accounts')->update(['email' => 'other@example.test']);
        $this->assertSame(ExternalGameAccountState::Conflict, $driver->inspect($database, $proof));
        $this->assertTrue($driver->changePassword($database, 'Mobius01', 'NewPassword123'));
        $this->assertSame(base64_encode(sha1('NewPassword123', true)), $database->table('accounts')->value('password'));
    }

    public function test_drivers_reject_each_others_credentials_and_resolver_has_no_unknown_fallback(): void
    {
        $mobius = app(MobiusLoginDriver::class);
        $rusacis = app(RusacisLoginDriver::class);
        $this->assertFalse($rusacis->acceptsCredential($mobius->encodePassword('Password123')));
        $this->assertFalse($mobius->acceptsCredential($rusacis->encodePassword('Password123')));
        $this->assertFalse($rusacis->acceptsCredential('$2a$99$'.str_repeat('a', 53)));
        $this->assertFalse($mobius->acceptsCredential('not-a-hash'));
        $this->assertInstanceOf(MobiusLoginDriver::class, app(LoginServerDriverResolver::class)->resolve('l2j_mobius_legacy'));
        $this->expectException(RuntimeException::class);
        app(LoginServerDriverResolver::class)->resolve('unknown');
    }

    public function test_gateway_rejects_wrong_proof_before_opening_any_external_connection(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $proof = $gateway->prepareAccount(new LoginServer(['driver' => 'l2j_mobius']), 'Player01', 'Password123', 'player@example.test');
        // No connection credentials are supplied: reaching transport would fail differently.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prepared credential does not match');
        $gateway->createPreparedAccount(new LoginServer(['driver' => 'rusacis']), $proof);
    }

    public function test_registration_revalidates_the_locked_login_and_game_driver_pair_before_preparing_credentials(): void
    {
        $login = LoginServer::factory()->create(['driver' => 'rusacis']);
        $game = GameServer::factory()->for($login)->create(['driver' => 'l2j_mobius']);
        $this->expectException(GameAccountCreationException::class);
        try {
            app(GameAccountProvisioner::class)->create(User::factory()->create(), $game, 'Mixed01', 'Password123', 'player@example.test', 10);
        } finally {
            $this->assertSame(0, UserGameAccount::query()->count());
        }
    }

    public function test_legacy_pending_operation_with_mismatched_driver_cannot_inspect_or_write_external_accounts(): void
    {
        $gateway = app(ExternalGameAccountGateway::class);
        $proof = $gateway->prepareAccount(new LoginServer(['driver' => 'l2j_mobius']), 'Pending01', 'Password123', 'player@example.test');
        $server = LoginServer::factory()->create(['driver' => 'rusacis']);
        $account = UserGameAccount::factory()->create([
            'login_server_id' => $server->id,
            'registration_game_server_id' => null,
            'game_login' => $proof->login,
            'normalized_login' => strtolower($proof->login),
            'creation_status' => UserGameAccount::STATUS_PENDING,
            'creation_credential' => $proof->credential,
            'creation_email' => $proof->email,
            'creation_uuid' => '93b155cc-5f5f-4c97-94a7-91386191eb51',
        ]);
        $manager = DB::getFacadeRoot();
        $transport = new class($manager)
        {
            public int $connections = 0;

            public function __construct(private object $manager) {}

            public function connectUsing(string $name, array $configuration, bool $force = false): never
            {
                $this->connections++;
                throw new RuntimeException('External access must not happen with an incompatible proof.');
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->manager->{$method}(...$arguments);
            }
        };
        DB::swap($transport);
        try {
            app(GameAccountProvisioner::class)->recover($account, retryMissing: true);
            $this->fail('Mismatched pending credentials must not activate an account.');
        } catch (GameAccountCreationException) {
            $this->assertSame(0, $transport->connections);
            $this->assertSame(UserGameAccount::STATUS_PENDING, $account->fresh()->creation_status);
            $this->assertSame(0, $account->fresh()->creation_attempts);
            $this->assertSame($proof->credential, $account->fresh()->creation_credential);
        } finally {
            DB::swap($manager);
        }
    }
}
