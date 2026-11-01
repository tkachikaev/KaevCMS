<?php

namespace Tests\Feature\Modules;

use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Services\ReferralLevelScanner;
use Tests\Fakes\FakeGameAccountGateway;
use Tests\TestCase;

class ReferralLevelScannerTest extends TestCase
{
    use RefreshDatabase;

    private FakeGameAccountGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->gateway = new FakeGameAccountGateway;
        $this->app->instance(GameAccountGateway::class, $this->gateway);
        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
    }

    public function test_first_character_creates_immutable_per_server_snapshot_and_earns_all_reached_levels(): void
    {
        [$invite, $server] = $this->scenario();
        $this->rule($server, 'inviter', 20, 57, 2);
        $this->rule($server, 'invitee', 40, 4037, 3);
        $this->rule($server, 'inviter', 76, 57, 5);
        $this->account($invite, $server);
        $this->gateway->charactersByServer[$server->id] = [['level' => 40]];

        $scanner = app(ReferralLevelScanner::class);
        $this->assertSame('updated', $scanner->scan($invite, $server));
        $this->assertDatabaseCount('module_referral_snapshots', 1);
        $this->assertDatabaseCount('module_referral_entitlements', 3);
        $this->assertDatabaseHas('module_referral_entitlements', [
            'invite_id' => $invite->id, 'audience' => 'inviter', 'level' => 20,
            'status' => 'earned', 'recipient_user_id' => $invite->inviter_user_id,
        ]);
        $this->assertDatabaseHas('module_referral_entitlements', [
            'invite_id' => $invite->id, 'audience' => 'invitee', 'level' => 40,
            'status' => 'earned', 'recipient_user_id' => $invite->invitee_user_id,
        ]);
        $this->assertDatabaseHas('module_referral_entitlements', ['level' => 76, 'status' => 'pending']);
        $this->assertDatabaseHas('module_referral_progress', ['max_seen_level' => 40, 'status' => 'pending']);
        $this->assertDatabaseCount('reward_inventory_grants', 0);

        // An administrator can change future rules, never already captured rewards.
        ReferralRule::query()->where('game_server_id', $server->id)->delete();
        $this->rule($server, 'inviter', 60, 8000, 9);
        Cache::flush();
        $this->gateway->charactersByServer[$server->id] = [['level' => 80]];
        $this->assertSame('completed', $scanner->scan($invite, $server, true));
        $this->assertDatabaseCount('module_referral_entitlements', 3);
        $this->assertDatabaseHas('module_referral_entitlements', [
            'audience' => 'inviter', 'level' => 76, 'item_id' => 57, 'amount' => 5, 'status' => 'earned',
        ]);
        $this->assertDatabaseHas('module_referral_progress', ['max_seen_level' => 80, 'status' => 'completed']);
        $this->assertSame('completed', $scanner->scan($invite, $server, true));
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_no_character_and_game_server_failure_never_create_a_snapshot_or_fake_zero_level(): void
    {
        [$invite, $server] = $this->scenario();
        $this->rule($server, 'inviter', 20, 57, 2);
        $this->account($invite, $server);
        $scanner = app(ReferralLevelScanner::class);
        $this->assertSame('waiting', $scanner->scan($invite, $server));
        $this->assertDatabaseCount('module_referral_snapshots', 0);

        Cache::flush();
        $this->gateway->failCharacters = true;
        $this->assertSame('unknown', $scanner->scan($invite, $server, true));
        $this->assertDatabaseHas('module_referral_progress', [
            'invite_id' => $invite->id, 'status' => 'unknown',
            'last_error_code' => 'game_server_unavailable',
        ]);
        $this->assertDatabaseCount('module_referral_snapshots', 0);
        $this->assertDatabaseCount('module_referral_entitlements', 0);
        $this->assertTrue(ReferralProgress::query()->firstOrFail()->next_check_at->isFuture());
    }

    public function test_new_server_on_same_login_server_is_discovered_independently_and_respects_cooldown(): void
    {
        [$invite, $first] = $this->scenario();
        $this->rule($first, 'inviter', 10, 57, 1);
        $this->account($invite, $first);
        $this->gateway->charactersByServer[$first->id] = [['level' => 20]];
        $scanner = app(ReferralLevelScanner::class);
        $this->assertSame('completed', $scanner->scan($invite, $first));

        $later = GameServer::factory()->create(['login_server_id' => $first->login_server_id]);
        $this->serverSetting($later);
        $this->rule($later, 'invitee', 40, 4037, 3);
        // A shared LoginServer does not establish any character on the new world.
        $this->assertSame('waiting', $scanner->scan($invite, $later));
        $this->assertDatabaseCount('module_referral_snapshots', 1);
        $this->gateway->charactersByServer[$later->id] = [['level' => 41]];
        $this->assertSame('not_due', $scanner->scan($invite, $later));
        Cache::flush();
        $counts = $scanner->scanBatch(10);
        $this->assertSame(0, $counts['due'] + $counts['discovery']);
        $this->assertSame('completed', $scanner->scan($invite, $later, true));
        $this->assertDatabaseCount('module_referral_snapshots', 2);
        $this->assertDatabaseHas('module_referral_entitlements', [
            'game_server_id' => $later->id, 'audience' => 'invitee', 'level' => 40, 'status' => 'earned',
        ]);
    }

    public function test_old_or_imported_game_accounts_do_not_count_and_frozen_invites_cannot_advance(): void
    {
        [$invite, $server] = $this->scenario();
        $this->rule($server, 'inviter', 10, 57, 1);
        $old = $this->account($invite, $server);
        $old->update(['created_via_cms' => false]);
        $this->gateway->charactersByServer[$server->id] = [['level' => 90]];
        $scanner = app(ReferralLevelScanner::class);
        $this->assertSame('waiting', $scanner->scan($invite, $server));
        $this->assertSame(0, $this->gateway->characterCalls);
        $old->forceFill(['created_via_cms' => true, 'creation_completed_at' => $invite->accepted_at->copy()->subDay()])->save();
        $this->assertSame('waiting', $scanner->scan($invite, $server, true));
        $this->assertSame(0, $this->gateway->characterCalls);

        $old->forceFill(['creation_completed_at' => now()])->save();
        $invite->update(['status' => 'frozen', 'frozen_reason' => 'Manual review']);
        $this->assertSame('disabled', $scanner->scan($invite->fresh(), $server, true));
        $this->assertDatabaseCount('module_referral_snapshots', 0);
    }

    public function test_server_pause_and_manual_check_permission_and_cooldown(): void
    {
        [$invite, $server] = $this->scenario();
        $this->rule($server, 'inviter', 20, 57, 1);
        $this->account($invite, $server);
        $scanner = app(ReferralLevelScanner::class);
        ReferralServerSetting::query()->where('game_server_id', $server->id)->update(['checks_paused' => true]);
        $this->assertSame('disabled', $scanner->scan($invite, $server));
        $this->assertDatabaseCount('module_referral_progress', 0);
        ReferralServerSetting::query()->where('game_server_id', $server->id)->update(['checks_paused' => false]);
        $other = User::factory()->create();
        $url = '/modules/referrals/check/'.$invite->id.'/'.$server->id;
        $this->actingAs($other)->post($url)->assertNotFound();
        $this->actingAs($invite->invitee)->post($url)->assertRedirect()->assertSessionHas('referral_check');
        $this->actingAs($invite->invitee)->post($url)->assertRedirect()->assertSessionHas('referral_check', 'cooldown');
        $this->assertSame('waiting', $scanner->scan($invite, $server, true));
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_scheduler_discovers_invitation_without_existing_entitlements(): void
    {
        [$invite, $server] = $this->scenario();
        $this->rule($server, 'inviter', 40, 57, 1);
        $this->account($invite, $server);
        $this->gateway->charactersByServer[$server->id] = [['level' => 40]];
        $scanner = app(ReferralLevelScanner::class);
        $result = $scanner->scanBatch(10);
        $this->assertSame(0, $result['due']);
        $this->assertSame(1, $result['discovery']);
        $this->assertDatabaseHas('module_referral_entitlements', [
            'invite_id' => $invite->id, 'level' => 40, 'status' => 'earned',
        ]);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    /** @return array{ReferralInvite,GameServer} */
    private function scenario(): array
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $code = ReferralCode::query()->create(['user_id' => $inviter->id, 'code' => str_repeat('R', 40)]);
        $invite = ReferralInvite::query()->create([
            'referral_code_id' => $code->id,
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'accepted_at' => now()->subHour(),
        ]);
        $server = GameServer::factory()->create();
        $this->serverSetting($server);

        return [$invite, $server];
    }

    private function serverSetting(GameServer $server): void
    {
        ReferralServerSetting::query()->create([
            'game_server_id' => $server->id, 'enabled' => true,
            'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => true,
        ]);
    }

    private function rule(GameServer $server, string $audience, int $level, int $itemId, int $amount): void
    {
        ReferralRule::query()->create([
            'game_server_id' => $server->id, 'audience' => $audience, 'level' => $level,
            'item_id' => $itemId, 'amount' => $amount, 'enabled' => true,
        ]);
    }

    private function account(ReferralInvite $invite, GameServer $server): UserGameAccount
    {
        return UserGameAccount::factory()->registeredOn($server)->create([
            'user_id' => $invite->invitee_user_id,
            'creation_completed_at' => now(), 'creation_status' => UserGameAccount::STATUS_ACTIVE,
            'created_via_cms' => true,
        ]);
    }
}
