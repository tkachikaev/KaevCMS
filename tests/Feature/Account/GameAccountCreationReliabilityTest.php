<?php

namespace Tests\Feature\Account;

use App\Contracts\GameAccountGateway;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithServerFixtures;
use Tests\Fakes\FakeGameAccountGateway;
use Tests\TestCase;

class GameAccountCreationReliabilityTest extends TestCase
{
    use InteractsWithServerFixtures, RefreshDatabase;

    private FakeGameAccountGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGameAccountGateway;
        $this->app->instance(GameAccountGateway::class, $this->gateway);
        app(GameAccountSettings::class)->update([
            'enabled' => true,
            'max_accounts' => 10,
            'login_min' => 4,
            'login_max' => 16,
            'login_digit' => false,
            'password_min' => 8,
            'password_max' => 32,
            'password_lower' => true,
            'password_upper' => true,
            'password_digit' => true,
        ]);
    }

    public function test_timeout_after_login_server_insert_is_verified_and_activated(): void
    {
        $user = User::factory()->create(['email' => 'timeout@example.com']);
        [, $gameServer] = $this->freshMobiusServerPair();
        $this->gateway->failCreateAfterWrite = true;

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'Timeout01'))
            ->assertRedirect();

        $account = UserGameAccount::query()->firstOrFail();
        $this->assertSame(UserGameAccount::STATUS_ACTIVE, $account->creation_status);
        $this->assertSame(1, $account->creation_attempts);
        $this->assertNotNull($account->creation_write_attempted_at);
        $this->assertNotNull($account->creation_completed_at);
        $this->assertCount(1, $this->gateway->created);
    }

    public function test_unknown_timeout_keeps_pending_operation_and_repeat_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'pending@example.com']);
        [, $gameServer] = $this->freshMobiusServerPair();
        $this->gateway->failCreateAfterWrite = true;
        $this->gateway->failInspectAfterCreate = true;

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'Pending01'))
            ->assertRedirect(public_route('game-accounts.index'))
            ->assertSessionHas('warning');

        $account = UserGameAccount::query()->firstOrFail();
        $this->assertSame(UserGameAccount::STATUS_PENDING, $account->creation_status);
        $this->assertSame('external_verification_unavailable', $account->creation_last_error);
        $this->assertNotNull($account->creation_uuid);
        $this->assertCount(1, $this->gateway->created);

        $this->gateway->failCreateAfterWrite = false;
        $this->gateway->failInspectAfterCreate = false;

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'Pending01'))
            ->assertRedirect();

        $account->refresh();
        $this->assertSame(UserGameAccount::STATUS_ACTIVE, $account->creation_status);
        $this->assertCount(1, $this->gateway->created);
    }

    public function test_matching_pre_existing_account_is_not_claimed_without_a_cms_write_attempt(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        [$loginServer, $gameServer] = $this->freshMobiusServerPair();
        $this->gateway->externalAccounts[$loginServer->id.':foreign01'] = [
            'credential' => 'StrongPass1',
            'email' => 'owner@example.com',
        ];

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'Foreign01'))
            ->assertSessionHasErrors('game_login');

        $account = UserGameAccount::query()->firstOrFail();
        $this->assertSame(UserGameAccount::STATUS_FAILED, $account->creation_status);
        $this->assertSame('external_account_exists', $account->creation_last_error);
        $this->assertNull($account->creation_write_attempted_at);
        $this->assertCount(0, $this->gateway->created);
    }

    public function test_duplicate_reported_by_login_server_is_never_claimed_even_when_values_match(): void
    {
        $user = User::factory()->create(['email' => 'race@example.com']);
        [, $gameServer] = $this->freshMobiusServerPair();
        $this->gateway->duplicateOnCreate = true;

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'RaceDup01'))
            ->assertSessionHasErrors('game_login');

        $account = UserGameAccount::query()->firstOrFail();
        $this->assertSame(UserGameAccount::STATUS_FAILED, $account->creation_status);
        $this->assertSame('external_account_exists', $account->creation_last_error);
        $this->assertNull($account->creation_write_attempted_at);
        $this->assertCount(0, $this->gateway->created);

        $this->artisan('kaevcms:game-accounts-recover', [
            'operation' => $account->creation_uuid,
        ])->assertExitCode(1);

        $account->refresh();
        $this->assertSame(UserGameAccount::STATUS_FAILED, $account->creation_status);
        $this->assertSame('external_account_exists', $account->creation_last_error);
    }

    public function test_failed_operation_can_be_retried_safely_from_console(): void
    {
        $user = User::factory()->create(['email' => 'recover@example.com']);
        [, $gameServer] = $this->freshMobiusServerPair();
        $this->gateway->failCreate = true;

        $this->actingAs($user)->post('/account/game-accounts', $this->payload($gameServer->id, 'Recover01'))
            ->assertSessionHasErrors('game_login');

        $account = UserGameAccount::query()->firstOrFail();
        $this->assertSame(UserGameAccount::STATUS_FAILED, $account->creation_status);
        $stored = (array) DB::table('user_game_accounts')->where('id', $account->id)->first([
            'creation_credential',
            'creation_email',
        ]);
        $this->assertArrayHasKey('creation_credential', $stored);
        $this->assertArrayHasKey('creation_email', $stored);
        $this->assertIsString($stored['creation_credential']);
        $this->assertIsString($stored['creation_email']);
        $this->assertNotSame('StrongPass1', $stored['creation_credential']);
        $this->assertNotSame('recover@example.com', $stored['creation_email']);

        $this->gateway->failCreate = false;

        $this->artisan('kaevcms:game-accounts-recover', [
            'operation' => $account->creation_uuid,
            '--retry' => true,
        ])->assertExitCode(0);

        $account->refresh();
        $this->assertSame(UserGameAccount::STATUS_ACTIVE, $account->creation_status);
        $this->assertSame(2, $account->creation_attempts);
        $this->assertNull($account->creation_credential);
        $this->assertCount(1, $this->gateway->created);
    }

    public function test_console_diagnostics_lists_only_stale_pending_operations(): void
    {
        $user = User::factory()->create();
        [$loginServer, $gameServer] = $this->freshMobiusServerPair();
        $uuid = (string) Str::uuid();

        $account = new UserGameAccount;
        $account->forceFill([
            'user_id' => $user->id,
            'login_server_id' => $loginServer->id,
            'registration_game_server_id' => $gameServer->id,
            'game_login' => 'Stale01',
            'normalized_login' => 'stale01',
            'created_via_cms' => true,
            'creation_uuid' => $uuid,
            'creation_status' => UserGameAccount::STATUS_PENDING,
            'creation_credential' => 'prepared-hash',
            'creation_email' => $user->email,
            'creation_attempts' => 1,
            'creation_last_error' => 'external_verification_unavailable',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $this->artisan('kaevcms:game-accounts-recover', [
            '--older-than' => 300,
        ])->expectsOutputToContain($uuid)
            ->assertExitCode(0);
    }

    /** @return array<string,mixed> */
    private function payload(int $gameServerId, string $login): array
    {
        return [
            'game_server_id' => $gameServerId,
            'game_login' => $login,
            'game_password' => 'StrongPass1',
            'game_password_confirmation' => 'StrongPass1',
        ];
    }
}
