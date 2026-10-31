<?php

namespace Tests\Feature\Modules;

use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RegistrationSettings;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use KaevCMS\Modules\Referrals\Services\ReferralRewardGrantService;
use RuntimeException;
use Tests\TestCase;

class ReferralRewardGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
    }

    public function test_both_sides_receive_distinct_inventory_grants_exactly_once(): void
    {
        [$invite, $server] = $this->scenario();
        $inviter = $this->earned($invite, $server, 'inviter', 20, 57, 2);
        $invitee = $this->earned($invite, $server, 'invitee', 40, 4037, 5);
        $service = app(ReferralRewardGrantService::class);

        $this->assertSame('granted', $service->grantEntitlement($inviter->id));
        $this->assertSame('granted', $service->grantEntitlement($invitee->id));
        $this->assertSame('already_granted', $service->grantEntitlement($inviter->id));
        $this->assertDatabaseCount('reward_inventory_grants', 2);
        $this->assertDatabaseCount('reward_inventory_items', 2);
        $this->assertDatabaseHas('reward_inventory_grants', [
            'grant_key' => 'referrals.entitlement.'.$inviter->id,
            'user_id' => $invite->inviter_user_id,
            'game_server_id' => $server->id,
            'source_type' => 'referral',
        ]);
        $this->assertDatabaseHas('reward_inventory_grants', [
            'grant_key' => 'referrals.entitlement.'.$invitee->id,
            'user_id' => $invite->invitee_user_id,
            'game_server_id' => $server->id,
        ]);
        $this->assertDatabaseHas('reward_inventory_items', [
            'user_id' => $invite->invitee_user_id, 'item_id' => 4037, 'amount' => 5,
            'status' => 'available',
        ]);
        $this->assertSame('granted', $inviter->fresh()->status);
        $this->assertNotNull($inviter->fresh()->reward_inventory_grant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referrals.entitlement_granted']);
    }

    public function test_completed_milestones_are_still_paid_in_separate_grant_batch(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 76, 57, 1);
        $result = app(ReferralRewardGrantService::class)->grantBatch(4);
        $this->assertSame(1, $result['granted']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('granted', $entitlement->fresh()->status);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertSame(0, app(ReferralRewardGrantService::class)->grantBatch(4)['granted']);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }

    public function test_crash_after_existing_grant_relinks_same_idempotent_grant_without_second_item(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'invitee', 40, 4037, 7);
        $grant = app(RewardInventoryService::class)->grant(
            user: $invite->invitee,
            server: $server,
            grantKey: 'referrals.entitlement.'.$entitlement->id,
            sourceType: 'referral',
            items: [new RewardGrantItem(4037, 7)],
            sourceReference: (string) $entitlement->id,
        );
        $this->assertSame('earned', $entitlement->fresh()->status);

        $this->assertSame('granted', app(ReferralRewardGrantService::class)->grantEntitlement($entitlement->id));
        $this->assertSame($grant->id, $entitlement->fresh()->reward_inventory_grant_id);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseCount('reward_inventory_items', 1);
    }

    public function test_replayed_key_with_changed_item_cannot_pay_or_link_wrong_grant(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 20, 57, 2);
        app(RewardInventoryService::class)->grant(
            user: $invite->inviter,
            server: $server,
            grantKey: 'referrals.entitlement.'.$entitlement->id,
            sourceType: 'referral',
            items: [new RewardGrantItem(4037, 999)],
            sourceReference: (string) $entitlement->id,
        );
        try {
            app(ReferralRewardGrantService::class)->grantEntitlement($entitlement->id);
            $this->fail('A mismatched idempotency key must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('module_referral_entitlements', [
                'id' => $entitlement->id, 'status' => 'earned', 'reward_inventory_grant_id' => null,
            ]);
        }
        $this->assertDatabaseCount('reward_inventory_grants', 1);
        $this->assertDatabaseCount('reward_inventory_items', 1);
    }

    public function test_frozen_invitation_and_paused_server_suspend_grants_without_losing_rights(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 10, 57, 1);
        $service = app(ReferralRewardGrantService::class);
        $invite->update(['status' => 'frozen', 'frozen_reason' => 'Manual review']);
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        $invite->update(['status' => 'active']);
        ReferralServerSetting::query()->where('game_server_id', $server->id)->update(['checks_paused' => true]);
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        ReferralServerSetting::query()->where('game_server_id', $server->id)->update([
            'checks_paused' => false, 'inviter_rewards_enabled' => false,
        ]);
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        ReferralServerSetting::query()->where('game_server_id', $server->id)->update(['inviter_rewards_enabled' => true]);
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => false]);
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        $this->assertDatabaseCount('reward_inventory_grants', 0);
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
        $this->assertSame('granted', $service->grantEntitlement($entitlement->id));
    }

    public function test_inactive_recipient_and_required_email_verification_block_grants(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'invitee', 20, 57, 1);
        $service = app(ReferralRewardGrantService::class);
        $invite->invitee->forceFill(['is_active' => false])->save();
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        $invite->invitee->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        app(RegistrationSettings::class)->update(true, true);
        $this->assertSame('deferred', $service->grantEntitlement($entitlement->id));
        $this->assertDatabaseCount('reward_inventory_grants', 0);

        // When verification is disabled, a technical email_verified_at value
        // is not used as an independent proof of ownership.
        app(RegistrationSettings::class)->update(true, false);
        $this->assertSame('granted', $service->grantEntitlement($entitlement->id));
    }

    public function test_malformed_or_unearned_entitlement_cannot_create_grant(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 40, 57, 3);
        ReferralProgress::query()->where('invite_id', $invite->id)->update(['max_seen_level' => 10]);
        $this->assertSame('deferred', app(ReferralRewardGrantService::class)->grantEntitlement($entitlement->id));
        ReferralProgress::query()->where('invite_id', $invite->id)->update(['max_seen_level' => 40]);
        $entitlement->update(['status' => 'pending', 'earned_at' => null]);
        $this->assertSame('deferred', app(ReferralRewardGrantService::class)->grantEntitlement($entitlement->id));
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_optional_cap_is_per_inviter_and_server_and_grandfathers_paid_invitations(): void
    {
        [$first, $server] = $this->scenario();
        [$second] = $this->scenario($first->inviter, $server);
        ReferralServerSetting::query()->where('game_server_id', $server->id)
            ->update(['max_rewarded_invitees' => 1]);
        $firstEarned = $this->earned($first, $server, 'inviter', 20, 57, 1);
        $secondEarned = $this->earned($second, $server, 'inviter', 20, 57, 1);
        $service = app(ReferralRewardGrantService::class);
        $this->assertSame('deferred', $service->grantEntitlement($secondEarned->id));
        $this->assertSame('granted', $service->grantEntitlement($firstEarned->id));
        $this->assertSame('deferred', $service->grantEntitlement($secondEarned->id));
        $this->assertSame('earned', $secondEarned->fresh()->status);
        $this->assertDatabaseCount('reward_inventory_grants', 1);

        $firstMore = $this->earned($first, $server, 'invitee', 30, 4037, 2);
        $this->assertSame('granted', $service->grantEntitlement($firstMore->id));
        ReferralServerSetting::query()->where('game_server_id', $server->id)
            ->update(['max_rewarded_invitees' => 2]);
        $this->assertSame('granted', $service->grantEntitlement($secondEarned->id));
        $this->assertDatabaseCount('reward_inventory_grants', 3);
    }

    public function test_failed_audit_rolls_back_grant_and_entitlement_together(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 20, 57, 1);
        $audit = \Mockery::mock(AuditLogger::class)->makePartial();
        $audit->shouldReceive('system')->once()->andReturnNull();
        $this->app->instance(AuditLogger::class, $audit);
        $this->app->forgetInstance(ReferralRewardGrantService::class);
        $this->app->forgetInstance(RewardInventoryService::class);
        try {
            app(ReferralRewardGrantService::class)->grantEntitlement($entitlement->id);
            $this->fail('Grant and entitlement must roll back when audit fails.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('reward_inventory_grants', 0);
            $this->assertDatabaseCount('reward_inventory_items', 0);
            $this->assertSame('earned', $entitlement->fresh()->status);
        }
    }

    public function test_failure_to_reconcile_one_side_does_not_duplicate_the_other_side(): void
    {
        [$invite, $server] = $this->scenario();
        $inviter = $this->earned($invite, $server, 'inviter', 20, 57, 2);
        $invitee = $this->earned($invite, $server, 'invitee', 20, 4037, 1);
        app(RewardInventoryService::class)->grant(
            user: $invite->inviter,
            server: $server,
            grantKey: 'referrals.entitlement.'.$inviter->id,
            sourceType: 'referral',
            items: [new RewardGrantItem(9999, 1)],
            sourceReference: (string) $inviter->id,
        );

        $result = app(ReferralRewardGrantService::class)->grantBatch(5);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['granted']);
        $this->assertSame('earned', $inviter->fresh()->status);
        $this->assertSame('granted', $invitee->fresh()->status);
        $this->assertDatabaseCount('reward_inventory_grants', 2);
        $this->assertDatabaseCount('reward_inventory_items', 2);
    }

    public function test_grant_batch_cursor_reaches_newer_eligible_rewards_behind_frozen_history(): void
    {
        [$first, $server] = $this->scenario();
        $first->update(['status' => 'frozen']);
        $this->earned($first, $server, 'inviter', 20, 57, 1);
        for ($i = 0; $i < 20; $i++) {
            [$frozen] = $this->scenario(null, $server);
            $frozen->update(['status' => 'frozen']);
            $this->earned($frozen, $server, 'inviter', 20, 57, 1);
        }
        [$active] = $this->scenario(null, $server);
        $last = $this->earned($active, $server, 'inviter', 20, 57, 1);

        $service = app(ReferralRewardGrantService::class);
        $this->assertSame(0, $service->grantBatch(1)['granted']);
        $this->assertSame(1, $service->grantBatch(1)['granted']);
        $this->assertSame('granted', $last->fresh()->status);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }

    public function test_grant_command_delivers_earned_rights_even_when_levels_are_complete(): void
    {
        [$invite, $server] = $this->scenario();
        $entitlement = $this->earned($invite, $server, 'inviter', 20, 57, 3);
        $this->artisan('kaevcms:referrals-grants', ['--batch' => 5])->assertExitCode(0);
        $this->assertSame('granted', $entitlement->fresh()->status);
        $this->assertDatabaseCount('reward_inventory_grants', 1);
    }

    public function test_independent_server_entitlements_do_not_share_idempotency_keys(): void
    {
        [$invite, $firstServer] = $this->scenario();
        $otherServer = GameServer::factory()->create();
        ReferralServerSetting::query()->create([
            'game_server_id' => $otherServer->id, 'enabled' => true,
            'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => true,
        ]);
        $one = $this->earned($invite, $firstServer, 'inviter', 20, 57, 2);
        $two = $this->earned($invite, $otherServer, 'inviter', 20, 57, 2);
        $service = app(ReferralRewardGrantService::class);
        $this->assertSame('granted', $service->grantEntitlement($one->id));
        $this->assertSame('granted', $service->grantEntitlement($two->id));
        $this->assertDatabaseCount('reward_inventory_grants', 2);
    }

    /** @return array{ReferralInvite,GameServer} */
    private function scenario(?User $inviter = null, ?GameServer $server = null): array
    {
        $inviter ??= User::factory()->create();
        $invitee = User::factory()->create();
        $code = ReferralCode::query()->firstOrCreate(
            ['user_id' => $inviter->id],
            ['code' => Str::random(40)],
        );
        $invite = ReferralInvite::query()->create([
            'referral_code_id' => $code->id,
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'accepted_at' => now()->subHour(),
        ]);
        $server ??= GameServer::factory()->create();
        ReferralServerSetting::query()->firstOrCreate(
            ['game_server_id' => $server->id],
            ['enabled' => true, 'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => true],
        );

        return [$invite, $server];
    }

    private function earned(ReferralInvite $invite, GameServer $server, string $audience, int $level, int $itemId, int $amount): ReferralEntitlement
    {
        $snapshot = ReferralSnapshot::query()->firstOrCreate(
            ['invite_id' => $invite->id, 'game_server_id' => $server->id],
            ['snapshotted_at' => now()->addSeconds($invite->id)],
        );
        ReferralProgress::query()->updateOrCreate(
            ['invite_id' => $invite->id, 'game_server_id' => $server->id],
            ['max_seen_level' => max($level, (int) (ReferralProgress::query()->where('invite_id', $invite->id)
                ->where('game_server_id', $server->id)->value('max_seen_level') ?? 0)),
                'status' => 'completed', 'next_check_at' => null],
        );

        return ReferralEntitlement::query()->create([
            'snapshot_id' => $snapshot->id,
            'invite_id' => $invite->id, 'game_server_id' => $server->id,
            'recipient_user_id' => $audience === 'inviter' ? $invite->inviter_user_id : $invite->invitee_user_id,
            'audience' => $audience, 'level' => $level,
            'item_id' => $itemId, 'amount' => $amount,
            'status' => 'earned', 'earned_at' => now(),
        ]);
    }
}
