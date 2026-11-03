<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use Tests\TestCase;

class ReferralAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_new_server_defaults_to_three_rewarded_friends_and_preserves_explicit_unlimited(): void
    {
        $owner = Admin::factory()->create();
        $server = GameServer::factory()->create();
        $response = $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/servers/'.$server->id)->assertOk();
        $this->assertSame(3, $response->viewData('settings')->max_rewarded_invitees);
        $settings = ReferralServerSetting::query()->create(['game_server_id' => $server->id]);
        $this->assertSame(3, $settings->fresh()->max_rewarded_invitees);
        $settings->update(['max_rewarded_invitees' => null]);
        $response = $this->get('/admin/extensions/referrals/servers/'.$server->id)->assertOk();
        $this->assertNull($response->viewData('settings')->max_rewarded_invitees);
    }

    public function test_adding_invitee_reward_returns_to_same_audience(): void
    {
        $owner = Admin::factory()->create();
        $server = GameServer::factory()->create();
        $this->actingAs($owner, 'admin')->post('/admin/extensions/referrals/servers/'.$server->id.'/rules', [
            'audience' => 'invitee', 'level' => 10, 'item_id' => 57, 'amount' => 1,
        ])->assertRedirect(route('admin.module-pages.referrals.servers.show', ['adminPath' => 'admin', 'server' => $server->id, 'audience' => 'invitee']));
    }

    public function test_async_rule_creation_returns_rendered_row_and_duplicate_validation_without_second_insert(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create();
        $url = '/admin/extensions/referrals/servers/'.$server->id.'/rules';
        $payload = ['audience' => 'invitee', 'level' => 55, 'item_id' => 57, 'amount' => 100];
        $response = $this->actingAs($owner, 'admin')->postJson($url, $payload)->assertOk()
            ->assertJsonPath('audience', 'invitee');
        $this->assertStringContainsString('referrals-rule-row', $response->json('html'));
        $this->assertSame(1, ReferralRule::query()->where('game_server_id', $server->id)->count());
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->assertSame(1, ReferralRule::query()->where('game_server_id', $server->id)->count());
    }

    public function test_owner_configures_global_invitations_and_ttl_with_audit(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/referrals/settings', ['enabled' => 1, 'invite_ttl_days' => 14])
            ->assertRedirect();

        $this->assertDatabaseHas('module_referral_settings', [
            'setting_key' => 'global', 'enabled' => true,
            'invite_ttl_days' => 14, 'updated_by_admin_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referrals.settings_updated', 'actor_id' => $owner->id]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/referrals/settings', ['enabled' => 1, 'invite_ttl_days' => 31])
            ->assertSessionHasErrors('invite_ttl_days');
        $this->assertDatabaseHas('module_referral_settings', ['invite_ttl_days' => 14]);
    }

    public function test_server_settings_and_two_independent_audiences_persist(): void
    {
        $owner = Admin::factory()->create();
        $server = GameServer::factory()->create();
        $this->actingAs($owner, 'admin')->put('/admin/extensions/referrals/servers/'.$server->id, [
            'enabled' => 1, 'checks_paused' => 0,
            'inviter_rewards_enabled' => 1, 'invitee_rewards_enabled' => 0,
            'max_rewarded_invitees' => '',
        ])->assertRedirect();
        $this->assertDatabaseHas('module_referral_server_settings', [
            'game_server_id' => $server->id, 'enabled' => true, 'checks_paused' => false,
            'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => false,
            'max_rewarded_invitees' => null,
        ]);

        foreach (['inviter', 'invitee'] as $audience) {
            $this->actingAs($owner, 'admin')->post('/admin/extensions/referrals/servers/'.$server->id.'/rules', [
                'audience' => $audience, 'level' => 40,
                'item_id' => $audience === 'inviter' ? 57 : 4037, 'amount' => 5,
            ])->assertRedirect();
        }
        $this->assertSame(2, ReferralRule::query()->where('game_server_id', $server->id)->count());
        $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/servers/'.$server->id.'?audience=invitee')
            ->assertOk()->assertSee(__('module-referrals::messages.rules_title'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'referrals.rule_created']);
    }

    public function test_duplicate_levels_invalid_items_and_excessive_rule_counts_are_rejected(): void
    {
        $owner = Admin::factory()->create();
        $server = GameServer::factory()->create();
        $url = '/admin/extensions/referrals/servers/'.$server->id.'/rules';
        $this->actingAs($owner, 'admin')->post($url, [
            'audience' => 'inviter', 'level' => 40, 'item_id' => 57, 'amount' => 5,
        ])->assertRedirect();
        $this->actingAs($owner, 'admin')->post($url, [
            'audience' => 'inviter', 'level' => 40, 'item_id' => 5, 'amount' => 5,
        ])->assertSessionHasErrors('level');
        $this->actingAs($owner, 'admin')->post($url, [
            'audience' => 'inviter', 'level' => 41, 'item_id' => 0, 'amount' => -2,
        ])->assertSessionHasErrors(['item_id', 'amount']);
        $this->assertSame(1, ReferralRule::query()->where('game_server_id', $server->id)->count());

        foreach (range(1, 29) as $level) {
            ReferralRule::query()->create([
                'game_server_id' => $server->id, 'audience' => 'inviter',
                'level' => $level, 'item_id' => 57, 'amount' => 1,
            ]);
        }
        $this->actingAs($owner, 'admin')->post($url, [
            'audience' => 'inviter', 'level' => 50, 'item_id' => 57, 'amount' => 5,
        ])->assertSessionHasErrors('level');
        $this->assertSame(30, ReferralRule::query()->where('game_server_id', $server->id)->count());
    }

    public function test_rule_deletion_cannot_target_another_game_server_or_change_snapshots(): void
    {
        $owner = Admin::factory()->create();
        $serverA = GameServer::factory()->create();
        $serverB = GameServer::factory()->create();
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $code = ReferralCode::query()->create(['user_id' => $inviter->id, 'code' => str_repeat('A', 40)]);
        $invite = ReferralInvite::query()->create([
            'referral_code_id' => $code->id, 'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id, 'accepted_at' => now(),
        ]);
        $snapshot = ReferralSnapshot::query()->create([
            'invite_id' => $invite->id, 'game_server_id' => $serverA->id, 'snapshotted_at' => now(),
        ]);
        $rule = ReferralRule::query()->create([
            'game_server_id' => $serverA->id, 'audience' => 'inviter',
            'level' => 20, 'item_id' => 57, 'amount' => 2,
        ]);
        $entitlement = ReferralEntitlement::query()->create([
            'snapshot_id' => $snapshot->id, 'invite_id' => $invite->id,
            'game_server_id' => $serverA->id, 'recipient_user_id' => $inviter->id,
            'audience' => 'inviter', 'level' => 20, 'item_id' => 57,
            'amount' => 2, 'status' => 'pending',
        ]);
        $this->actingAs($owner, 'admin')
            ->delete('/admin/extensions/referrals/servers/'.$serverB->id.'/rules/'.$rule->id)
            ->assertNotFound();
        $this->assertDatabaseHas('module_referral_rules', ['id' => $rule->id]);
        $this->actingAs($owner, 'admin')
            ->delete('/admin/extensions/referrals/servers/'.$serverA->id.'/rules/'.$rule->id)
            ->assertRedirect();
        $this->assertDatabaseMissing('module_referral_rules', ['id' => $rule->id]);
        $this->assertDatabaseHas('module_referral_snapshots', ['id' => $snapshot->id]);
        $this->assertDatabaseHas('module_referral_entitlements', ['id' => $entitlement->id, 'item_id' => 57, 'amount' => 2]);
    }

    public function test_freeze_and_unfreeze_are_audited_without_erasing_invitation(): void
    {
        $owner = Admin::factory()->create();
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $code = ReferralCode::query()->create(['user_id' => $inviter->id, 'code' => str_repeat('B', 40)]);
        $invite = ReferralInvite::query()->create([
            'referral_code_id' => $code->id, 'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id, 'accepted_at' => now(),
        ]);
        $url = '/admin/extensions/referrals/invites/'.$invite->id;
        $this->actingAs($owner, 'admin')->patch($url.'/freeze', ['reason' => 'Manual review'])->assertRedirect();
        $this->assertDatabaseHas('module_referral_invites', [
            'id' => $invite->id, 'status' => 'frozen',
            'frozen_reason' => 'Manual review', 'frozen_by_admin_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referrals.invite_frozen']);
        $this->actingAs($owner, 'admin')->patch($url.'/freeze', ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->actingAs($owner, 'admin')->patch($url.'/unfreeze')->assertRedirect();
        $this->assertDatabaseHas('module_referral_invites', ['id' => $invite->id, 'status' => 'active', 'frozen_reason' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referrals.invite_unfrozen']);
    }

    public function test_auditor_can_read_but_cannot_modify_and_editor_cannot_read(): void
    {
        $server = GameServer::factory()->create();
        $auditor = Admin::factory()->auditor()->create();
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        // The overview opens the editor of the first game server.
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/referrals')
            ->assertRedirectContains('/admin/extensions/referrals/servers/');
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/referrals/servers/'.$server->id)->assertOk()
            ->assertDontSee('data-referrals-add-rule', false);
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/referrals/invites')->assertOk();
        $this->actingAs($auditor, 'admin')->put('/admin/extensions/referrals/settings', [
            'enabled' => 1, 'invite_ttl_days' => 7,
        ])->assertForbidden();
        $this->actingAs($auditor, 'admin')->post('/admin/extensions/referrals/servers/'.$server->id.'/rules', [
            'audience' => 'inviter', 'level' => 20, 'item_id' => 57, 'amount' => 1,
        ])->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/extensions/referrals')->assertForbidden();
    }
}
