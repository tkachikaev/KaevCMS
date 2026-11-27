<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use Tests\TestCase;

final class VotingInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-2222-4333-8444-555555555555';

    private const INTENT = '22222222-3333-4444-8555-666666666666';

    private const ENDPOINT = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-intents';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
        Http::preventStrayRequests();
    }

    private function configure(bool $playerAccess = true): GameServer
    {
        // Seed a configured program without performing a live provider check.
        VotingSetting::query()->where('provider_code', 'ktreon')->firstOrFail()->forceFill([
            'enabled' => true,
            'player_access_enabled' => $playerAccess,
            'project_id' => self::PROJECT,
            'api_key_ciphertext' => Crypt::encryptString('kti_'.str_repeat('a', 43)),
        ])->save();
        $server = GameServer::factory()->create();
        (new VotingServerReward)->forceFill([
            'game_server_id' => $server->id,
            'enabled' => true,
            'items' => [['item_id' => 57, 'amount' => 100]],
        ])->save();

        return $server;
    }

    private function account(GameServer $server, User $user): UserGameAccount
    {
        return UserGameAccount::factory()->registeredOn($server)->create(['user_id' => $user->id]);
    }

    /** @return array{intent_id:string,vote_url:string,expires_at:string} */
    private function response(?string $url = null): array
    {
        return [
            'intent_id' => self::INTENT,
            'vote_url' => $url ?? 'https://ktreon.com/vote/kvi_'.str_repeat('b', 43),
            'expires_at' => now()->utc()->addMinutes(10)->toIso8601String(),
        ];
    }

    /** @return array{game_server_id:int,user_game_account_id:int} */
    private function payload(GameServer $server, UserGameAccount $account): array
    {
        return ['game_server_id' => $server->id, 'user_game_account_id' => $account->id];
    }

    public function test_player_access_is_closed_even_if_provider_and_rewards_are_configured(): void
    {
        $server = $this->configure(false);
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        $this->actingAs($user)->get(route('modules.voting.index'))->assertNotFound();
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertNotFound();
        $this->assertNotContains('modules.voting.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $this->assertDatabaseCount('module_voting_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_guest_cannot_prepare_invitation(): void
    {
        $server = $this->configure();
        $account = $this->account($server, User::factory()->create());
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertStatus(302);
        $this->assertDatabaseCount('module_voting_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_foreign_pending_or_wrong_login_account_cannot_create_invitation(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $foreign = $this->account($server, User::factory()->create());
        $pending = $this->account($server, $user);
        $pending->forceFill(['creation_status' => UserGameAccount::STATUS_PENDING])->save();
        $wrongServer = GameServer::factory()->create();
        $wrongLogin = $this->account($wrongServer, $user);
        $this->actingAs($user);
        foreach ([$foreign, $pending, $wrongLogin] as $account) {
            $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertNotFound();
        }
        $this->assertDatabaseCount('module_voting_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_unconfigured_server_and_empty_bundle_cannot_create_invitation(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        $unconfigured = GameServer::factory()->create(['login_server_id' => $server->login_server_id]);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($unconfigured, $account))->assertNotFound();
        VotingServerReward::query()->where('game_server_id', $server->id)->firstOrFail()->forceFill(['items' => []])->save();
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertNotFound();
        $this->assertDatabaseCount('module_voting_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_retry_reuses_persisted_uuid_and_opaque_recipient_and_never_grants_items(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        $response = $this->response();
        $requests = [];
        Http::fake([self::ENDPOINT => function (Request $request) use (&$requests, $response) {
            $binding = VotingBinding::query()->firstOrFail();
            $this->assertTrue($request->hasHeader('Idempotency-Key', (string) $binding->request_id));
            $this->assertSame(['player_ref' => $binding->player_ref], $request->data());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer kti_'.str_repeat('a', 43)));
            $requests[] = [$binding->request_id, $binding->player_ref];

            if (count($requests) === 1) {
                throw new ConnectionException('Simulated network timeout.');
            }

            return Http::response($response, 201);
        }]);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertRedirect()->assertSessionHasErrors('voting');
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertStatus(303)->assertRedirect($response['vote_url'])->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0], $requests[1]);
        $this->assertDatabaseHas('module_voting_bindings', [
            'project_id' => self::PROJECT, 'user_id' => $user->id,
            'user_game_account_id' => $account->id, 'game_server_id' => $server->id, 'intent_id' => self::INTENT,
        ]);
        $this->assertDatabaseCount('module_voting_bindings', 1);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertStringNotContainsString($response['vote_url'], json_encode(DB::table('module_voting_bindings')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_invalid_calendar_expiration_is_not_normalized_into_a_valid_invitation(): void
    {
        $this->travelTo(Carbon::parse('2026-03-02 00:00:00', 'UTC'));
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        $payload = array_replace($this->response(), ['expires_at' => '2026-02-30T00:05:00Z']);
        Http::fake([self::ENDPOINT => Http::response($payload, 201)]);

        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertRedirect(route('modules.voting.index', ['server' => $server->id]))
            ->assertSessionHasErrors('voting');
        $this->assertDatabaseHas('module_voting_bindings', ['intent_id' => null]);
    }

    public function test_unauthorized_invitation_closes_player_access_until_checked_again(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fake([self::ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);

        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertRedirect(route('modules.voting.history'))->assertSessionHasErrors('voting');
        $this->get(route('modules.voting.history'))->assertOk()->assertSee(__('module-voting::messages.invite_unauthorized'));
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'last_check_result' => 'unauthorized']);
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_corrupt_encrypted_key_closes_access_without_network_call(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        VotingSetting::query()->update(['api_key_ciphertext' => 'invalid-ciphertext']);

        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertRedirect(route('modules.voting.history'))->assertSessionHasErrors('voting');
        $this->assertDatabaseHas('module_voting_settings', ['player_access_enabled' => false, 'last_check_result' => 'not_configured']);
        $this->assertDatabaseCount('module_voting_bindings', 0);
        Http::assertNothingSent();
    }

    public function test_provider_cannot_redirect_player_to_an_untrusted_origin(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fake([self::ENDPOINT => Http::response($this->response('https://ktreon.com.evil.example/vote/kvi_'.str_repeat('b', 43)), 201)]);
        $result = $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account));
        $result->assertRedirect(route('modules.voting.index', ['server' => $server->id]))->assertSessionHasErrors('voting');
        $this->assertDatabaseHas('module_voting_bindings', ['intent_id' => null]);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_expired_invitation_replaces_uuid_but_never_changes_player_reference(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fakeSequence(self::ENDPOINT)->push(['error' => 'intent_unavailable'], 409)->push($this->response(), 201);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))->assertSessionHasErrors('voting');
        $before = VotingBinding::query()->firstOrFail();
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertStatus(303);
        $after = $before->fresh();
        $this->assertNotSame($before->request_id, $after->request_id);
        $this->assertSame($before->player_ref, $after->player_ref);
    }

    public function test_rate_limit_blocks_further_network_calls_until_retry_after(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fake([self::ENDPOINT => Http::response(['error' => 'rate_limited'], 429, ['Retry-After' => '60'])]);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))->assertSessionHasErrors('voting');
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))->assertSessionHasErrors('voting');
        Http::assertSentCount(1);
        $this->assertTrue(VotingSetting::query()->firstOrFail()->intent_retry_at->isFuture());
    }

    public function test_project_cannot_be_changed_after_invitation_but_key_rotation_is_allowed(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fake([self::ENDPOINT => Http::response($this->response(), 201)]);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))->assertStatus(303);
        $admin = Admin::factory()->create();
        $url = '/admin/extensions/voting/providers/ktreon';
        $this->actingAs($admin, 'admin')->put($url, [
            'enabled' => 1, 'project_id' => '33333333-4444-4555-8666-777777777777',
            'password' => 'kti_'.str_repeat('c', 43), 'pending_policy' => 'wait',
        ])->assertSessionHasErrors('project_id');
        $this->put($url, [
            'enabled' => 1, 'project_id' => self::PROJECT,
            'password' => 'kti_'.str_repeat('c', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        $this->assertDatabaseHas('module_voting_settings', ['project_id' => self::PROJECT, 'credential_revision' => 1]);
        $this->assertDatabaseCount('module_voting_bindings', 1);
    }

    public function test_credentials_changed_during_request_cannot_redirect_with_old_invitation(): void
    {
        $server = $this->configure();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        Http::fake([self::ENDPOINT => function () {
            VotingSetting::query()->where('provider_code', 'ktreon')->update(['credential_revision' => 1]);

            return Http::response($this->response(), 201);
        }]);
        $this->actingAs($user)->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertRedirect(route('modules.voting.index', ['server' => $server->id]))->assertSessionHasErrors('voting');
        $this->assertDatabaseHas('module_voting_bindings', ['intent_id' => null]);
    }

    public function test_shared_login_server_uses_distinct_immutable_recipient_references(): void
    {
        $server = $this->configure();
        $other = GameServer::factory()->create(['login_server_id' => $server->login_server_id]);
        (new VotingServerReward)->forceFill([
            'game_server_id' => $other->id, 'enabled' => true, 'items' => [['item_id' => 57, 'amount' => 1]],
        ])->save();
        $user = User::factory()->create();
        $account = $this->account($server, $user);
        // Each HTTP request owns its own response stream. Distinct recipients receive distinct invitations.
        $firstResponse = $this->response();
        $secondResponse = array_replace($this->response('https://ktreon.com/vote/kvi_'.str_repeat('c', 43)), [
            'intent_id' => '33333333-4444-4555-8666-777777777777',
        ]);
        Http::fakeSequence(self::ENDPOINT)->push($firstResponse, 201)->push($secondResponse, 201);

        $this->actingAs($user)->get(route('modules.voting.index', ['server' => $server->id]))
            ->assertOk()->assertSee($account->game_login);
        $this->post(route('modules.voting.invite'), $this->payload($server, $account))
            ->assertStatus(303)->assertRedirect($firstResponse['vote_url']);
        $this->post(route('modules.voting.invite'), $this->payload($other, $account))
            ->assertStatus(303)->assertRedirect($secondResponse['vote_url']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('module_voting_bindings', 2);
        $first = VotingBinding::query()->where('game_server_id', $server->id)->firstOrFail();
        $second = VotingBinding::query()->where('game_server_id', $other->id)->firstOrFail();
        $this->assertNotSame($first->player_ref, $second->player_ref);
        $this->assertNotSame($first->request_id, $second->request_id);
        $this->assertSame($firstResponse['intent_id'], $first->intent_id);
        $this->assertSame($secondResponse['intent_id'], $second->intent_id);
        $this->assertSame($first->user_game_account_id, $second->user_game_account_id);
    }
}
