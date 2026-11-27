<?php

namespace Tests\Feature\Modules;

use App\Models\GameServer;
use App\Models\ModuleState;
use App\Models\RewardInventoryGrant;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingEvent;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use KaevCMS\Modules\Voting\Models\VotingVote;
use KaevCMS\Modules\Voting\Services\VotingSyncService;
use Mockery;
use Tests\TestCase;

final class VotingSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-2222-4333-8444-555555555555';

    private const VOTE = '22222222-3333-4444-8555-666666666666';

    private const ENDPOINT = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
        Http::preventStrayRequests();
    }

    private function configure(string $policy = 'wait'): VotingBinding
    {
        // A paused provider must still drain events from previously issued invitations.
        VotingSetting::query()->firstOrFail()->forceFill([
            'enabled' => false,
            'player_access_enabled' => false,
            'project_id' => self::PROJECT,
            'api_key_ciphertext' => Crypt::encryptString('kti_'.str_repeat('a', 43)),
            'pending_policy' => $policy,
        ])->save();
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $account = UserGameAccount::factory()->registeredOn($server)->create(['user_id' => $user->id]);
        (new VotingServerReward)->forceFill([
            'game_server_id' => $server->id,
            'enabled' => true,
            'items' => [['item_id' => 57, 'amount' => 100]],
        ])->save();
        $binding = new VotingBinding;
        $binding->forceFill([
            'project_id' => self::PROJECT,
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'user_game_account_id' => $account->id,
            'player_ref' => str_repeat('a', 64),
            'request_id' => (string) Str::uuid(),
        ])->save();

        return $binding;
    }

    /** @return array<string,mixed> */
    private function event(VotingBinding $binding, int $sequence, string $status, int $version, string $vote = self::VOTE): array
    {
        return [
            'cursor' => (string) $sequence,
            'event_id' => sprintf('33333333-4444-4555-8666-%012d', $sequence),
            'vote_id' => $vote,
            'project_id' => self::PROJECT,
            'player_ref' => $binding->player_ref,
            'status' => $status,
            'status_version' => $version,
            'voted_at' => '2026-11-26T00:00:00Z',
            'status_changed_at' => '2026-11-26T01:00:00Z',
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array{events:array,next_cursor:string,has_more:bool}
     */
    private function page(array $events, string $cursor = '0'): array
    {
        return ['events' => $events, 'next_cursor' => $events === [] ? $cursor : (string) $events[array_key_last($events)]['cursor'], 'has_more' => false];
    }

    public function test_pending_accepted_revoked_and_accepted_reward_only_once_with_original_snapshot(): void
    {
        $binding = $this->configure();
        $pending = $this->event($binding, 1, 'pending', 1);
        $accepted = $this->event($binding, 3, 'accepted', 2);
        $revoked = $this->event($binding, 4, 'revoked', 3);
        $again = $this->event($binding, 8, 'accepted', 4);
        Http::fakeSequence(self::ENDPOINT.'*')
            ->push($this->page([$pending]))
            ->push($this->page([$accepted, $revoked, $again]))
            ->push($this->page([$pending, $accepted, $revoked, $again]));
        $sync = app(VotingSyncService::class);
        $this->assertSame('ok', $sync->sync()['result']);
        $this->assertDatabaseHas('module_voting_votes', ['status' => 'pending', 'reward_state' => 'waiting']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        VotingServerReward::query()->firstOrFail()->forceFill(['items' => [['item_id' => 57, 'amount' => 999]]])->save();
        $this->assertSame(1, $sync->sync()['granted']);
        $this->assertDatabaseHas('reward_inventory_items', ['user_id' => $binding->user_id, 'game_server_id' => $binding->game_server_id, 'item_id' => 57, 'amount' => 100]);
        $this->assertDatabaseHas('module_voting_votes', ['status' => 'accepted', 'status_version' => 4, 'reward_state' => 'granted']);
        VotingSetting::query()->update(['event_cursor' => '0']);
        $this->assertSame(0, $sync->sync()['granted']);
        $this->assertDatabaseCount('module_voting_events', 4);
        $this->assertDatabaseCount('module_voting_votes', 1);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '8']);
    }

    public function test_immediate_pending_grant_is_preserved_when_vote_is_rejected(): void
    {
        $binding = $this->configure('immediate');
        Http::fakeSequence(self::ENDPOINT.'*')
            ->push($this->page([$this->event($binding, 1, 'pending', 1)]))
            ->push($this->page([$this->event($binding, 2, 'rejected', 2)]));
        $sync = app(VotingSyncService::class);
        $this->assertSame(1, $sync->sync()['granted']);
        $this->assertSame(0, $sync->sync()['granted']);
        $this->assertDatabaseHas('module_voting_votes', ['status' => 'rejected', 'reward_state' => 'granted']);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }

    public function test_policy_change_does_not_award_an_already_processed_pending_event(): void
    {
        $binding = $this->configure();
        $pending = $this->event($binding, 1, 'pending', 1);
        Http::fakeSequence(self::ENDPOINT.'*')->push($this->page([$pending]))->push($this->page([$pending]));
        $sync = app(VotingSyncService::class);
        $sync->sync();
        VotingSetting::query()->update(['pending_policy' => 'immediate', 'event_cursor' => '0']);
        $this->assertSame('ok', $sync->sync()['result']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_unknown_case_mismatch_and_removed_account_do_not_receive_rewards(): void
    {
        $binding = $this->configure();
        $unknown = $this->event($binding, 1, 'accepted', 1);
        $unknown['player_ref'] = strtoupper($binding->player_ref);
        $deleted = $this->event($binding, 2, 'accepted', 1, '44444444-5555-4666-8777-888888888888');
        UserGameAccount::query()->whereKey($binding->user_game_account_id)->delete();
        Http::fake([self::ENDPOINT.'*' => Http::response($this->page([$unknown, $deleted]))]);
        $this->assertSame('ok', app(VotingSyncService::class)->sync()['result']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertDatabaseCount('module_voting_events', 2);
        $this->assertSame(2, VotingVote::query()->where('reward_state', 'ineligible')->count());
        $this->assertDatabaseHas('module_voting_votes', ['vote_id' => self::VOTE, 'binding_id' => null]);
    }

    public function test_account_removed_after_reward_does_not_block_status_updates_or_regrant(): void
    {
        $binding = $this->configure();
        Http::fakeSequence(self::ENDPOINT.'*')
            ->push($this->page([$this->event($binding, 1, 'accepted', 1)]))
            ->push($this->page([$this->event($binding, 2, 'revoked', 2)]));
        $sync = app(VotingSyncService::class);
        $this->assertSame(1, $sync->sync()['granted']);
        UserGameAccount::query()->whereKey($binding->user_game_account_id)->delete();
        $this->assertSame('ok', $sync->sync()['result']);
        $this->assertDatabaseHas('module_voting_votes', ['status' => 'revoked', 'reward_state' => 'granted', 'user_game_account_id' => null]);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }

    public function test_existing_inventory_ledger_recovers_missing_vote_without_new_reward(): void
    {
        $binding = $this->configure();
        $accepted = $this->event($binding, 1, 'accepted', 1);
        Http::fakeSequence(self::ENDPOINT.'*')->push($this->page([$accepted]))->push($this->page([$accepted]));
        $sync = app(VotingSyncService::class);
        $this->assertSame(1, $sync->sync()['granted']);
        $grant = RewardInventoryGrant::query()->firstOrFail();
        VotingEvent::query()->delete();
        VotingVote::query()->delete();
        VotingSetting::query()->update(['event_cursor' => '0']);
        VotingServerReward::query()->firstOrFail()->forceFill(['items' => [['item_id' => 57, 'amount' => 999]]])->save();
        $this->assertSame('ok', $sync->sync()['result']);
        $this->assertDatabaseHas('module_voting_votes', ['reward_inventory_grant_id' => $grant->id, 'reward_state' => 'granted']);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseHas('reward_inventory_items', ['amount' => 100]);
    }

    public function test_audit_failure_rolls_back_inventory_vote_event_and_cursor_then_retry_succeeds(): void
    {
        $binding = $this->configure();
        $accepted = $this->event($binding, 1, 'accepted', 1);
        Http::fakeSequence(self::ENDPOINT.'*')->push($this->page([$accepted]))->push($this->page([$accepted]));
        $audit = Mockery::mock(AuditLogger::class)->makePartial();
        $audit->shouldReceive('system')->with('module', 'voting.reward_granted', Mockery::type(RewardInventoryGrant::class), Mockery::type('array'))->once()->andReturnNull();
        $this->app->instance(AuditLogger::class, $audit);
        $this->assertSame('failed', app(VotingSyncService::class)->sync()['result']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertDatabaseCount('reward_inventory_items', 0);
        $this->assertDatabaseCount('module_voting_votes', 0);
        $this->assertDatabaseCount('module_voting_events', 0);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '0']);
        $this->app->instance(AuditLogger::class, new AuditLogger);
        $this->travel(6)->minutes();
        $this->assertSame(1, app(VotingSyncService::class)->sync()['granted']);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '1']);
    }

    public function test_conflicting_event_replay_rolls_back_other_grants_on_same_page(): void
    {
        $binding = $this->configure();
        $first = $this->event($binding, 2, 'pending', 1);
        $new = $this->event($binding, 1, 'accepted', 1, '44444444-5555-4666-8777-888888888888');
        $conflict = array_replace($first, ['status' => 'accepted']);
        Http::fakeSequence(self::ENDPOINT.'*')->push($this->page([$first]))->push($this->page([$new, $conflict]));
        $sync = app(VotingSyncService::class);
        $this->assertSame('ok', $sync->sync()['result']);
        VotingSetting::query()->update(['event_cursor' => '0']);
        $this->assertSame('failed', $sync->sync()['result']);
        $this->assertDatabaseCount('module_voting_events', 1);
        $this->assertDatabaseCount('module_voting_votes', 1);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '0']);
    }

    public function test_rate_limit_delays_polling_without_moving_cursor(): void
    {
        $binding = $this->configure();
        Http::fakeSequence(self::ENDPOINT.'*')
            ->push(['error' => 'rate_limited'], 429, ['Retry-After' => '120'])
            ->push($this->page([$this->event($binding, 1, 'accepted', 1)]));
        $sync = app(VotingSyncService::class);
        $this->assertSame('rate_limited', $sync->sync()['result']);
        $this->assertSame('cooldown', $sync->sync()['result']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '0']);
        $this->travel(121)->seconds();
        $this->assertSame(1, $sync->sync()['granted']);
    }

    public function test_concurrent_fetch_cannot_commit_a_page_after_another_sync_advances_cursor(): void
    {
        $binding = $this->configure();
        $page = $this->page([$this->event($binding, 1, 'accepted', 1)]);
        $first = true;
        Http::fake([self::ENDPOINT.'*' => function (Request $request) use (&$first, $page) {
            if ($first) {
                $first = false;
                $this->assertSame(1, app(VotingSyncService::class)->sync()['granted']);
            }

            return Http::response($page);
        }]);
        $this->assertSame('changed', app(VotingSyncService::class)->sync()['result']);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseCount('module_voting_events', 1);
    }

    public function test_rotation_during_fetch_discards_old_response(): void
    {
        $binding = $this->configure();
        Http::fake([self::ENDPOINT.'*' => function () use ($binding) {
            VotingSetting::query()->update(['credential_revision' => 1]);

            return Http::response($this->page([$this->event($binding, 1, 'accepted', 1)]));
        }]);
        $this->assertSame('changed', app(VotingSyncService::class)->sync()['result']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        $this->assertDatabaseCount('module_voting_events', 0);
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '0']);
    }

    public function test_disabled_module_does_not_poll_existing_bindings(): void
    {
        $this->configure();
        ModuleState::query()->whereKey('voting')->update(['enabled' => false]);
        $this->assertSame('idle', app(VotingSyncService::class)->sync()['result']);
        Http::assertNothingSent();
    }
}
