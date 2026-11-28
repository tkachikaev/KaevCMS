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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class LoginServerDriversTest extends TestCase
{
    use RefreshDatabase;

    public function test_drivers_reject_each_others_credentials_and_resolver_has_no_unknown_fallback(): void
    {
        $resolver = app(LoginServerDriverResolver::class);
        $mobius = $resolver->resolve('l2j_mobius');
        $rusacis = $resolver->resolve('rusacis');
        $this->assertFalse($rusacis->acceptsCredential($mobius->encodePassword('Password123')));
        $this->assertFalse($mobius->acceptsCredential($rusacis->encodePassword('Password123')));
        $this->assertFalse($rusacis->acceptsCredential('$2a$99$'.str_repeat('a', 53)));
        $this->assertFalse($mobius->acceptsCredential('not-a-hash'));
        $this->assertSame(base64_encode(sha1('Password123', true)), $resolver->resolve('l2j_mobius_legacy')->encodePassword('Password123'));
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
