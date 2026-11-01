<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use Tests\TestCase;

class ReferralAccountInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_unconfigured_program_is_hidden_from_sidebar_and_player_page(): void
    {
        $user = User::factory()->create();
        $this->assertNotContains('modules.referrals.index', array_column(app(ModuleNavigationRegistry::class)->sidebarAccountLinks(), 'route'));
        $this->actingAs($user)->get(route('modules.referrals.index'))->assertNotFound();
    }

    public function test_link_stays_hidden_when_only_disabled_audience_has_rules(): void
    {
        $server = $this->configureServer();
        ReferralServerSetting::query()->where('game_server_id', $server->id)
            ->update(['inviter_rewards_enabled' => false, 'invitee_rewards_enabled' => true]);
        $this->assertNotContains('modules.referrals.index', array_column(app(ModuleNavigationRegistry::class)->sidebarAccountLinks(), 'route'));
        $this->actingAs(User::factory()->create())->get(route('modules.referrals.index'))->assertNotFound();
    }

    public function test_configured_program_uses_existing_account_layout_and_shows_link_and_rules(): void
    {
        $server = $this->configureServer();
        $user = User::factory()->create();
        $this->assertContains('modules.referrals.index', array_column(app(ModuleNavigationRegistry::class)->sidebarAccountLinks(), 'route'));
        $response = $this->actingAs($user)->get(route('modules.referrals.index'));
        $response->assertOk()
            ->assertViewIs('module-referrals::account.index')
            ->assertSee(__('module-referrals::messages.account_title'))
            ->assertSee(__('module-referrals::messages.copy_link'))
            ->assertSee('data-account-copy-target="referral-link"', false)
            ->assertSee('/r/'.ReferralCode::query()->where('user_id', $user->id)->firstOrFail()->code)
            ->assertSee($server->nameFor())
            ->assertSee(__('module-referrals::messages.friend_level', ['level' => 20]))
            ->assertDontSee(__('module-referrals::messages.open_inventory'));
    }

    public function test_disabled_program_hides_link_even_after_it_was_configured(): void
    {
        $this->configureServer();
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('modules.referrals.index'))->assertOk();
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => false]);
        $this->assertNotContains('modules.referrals.index', array_column(app(ModuleNavigationRegistry::class)->sidebarAccountLinks(), 'route'));
        $this->actingAs($user)->get(route('modules.referrals.index'))->assertNotFound();
    }

    public function test_progress_and_rewards_are_scoped_to_current_user_and_server(): void
    {
        $server = $this->configureServer();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $other = User::factory()->create();
        $invite = $this->invite($first, $second);
        $unrelated = $this->invite($other, User::factory()->create());
        $this->entitlement($invite, $server, $first, 20, 57);
        $this->entitlement($unrelated, $server, $other, 30, 4037);
        ReferralProgress::query()->create([
            'invite_id' => $invite->id, 'game_server_id' => $server->id,
            'max_seen_level' => 20, 'status' => 'pending',
        ]);
        ReferralProgress::query()->create([
            'invite_id' => $unrelated->id, 'game_server_id' => $server->id,
            'max_seen_level' => 30, 'status' => 'pending',
        ]);

        $response = $this->actingAs($first)->get(route('modules.referrals.index', ['tab' => 'progress']));
        $response->assertOk()
            ->assertSee(__('module-referrals::messages.invitation_number', ['number' => $invite->id]))
            ->assertDontSee(__('module-referrals::messages.invitation_number', ['number' => $unrelated->id]))
            ->assertSee('modules/referrals/check/'.$invite->id.'/'.$server->id)
            ->assertDontSee('modules/referrals/check/'.$unrelated->id.'/'.$server->id);
        $this->assertSame(1, $response->viewData('rewards')->total());
        $this->assertSame(1, $response->viewData('progress')->count());
    }

    public function test_guest_is_not_allowed_into_private_referrals_page(): void
    {
        $this->configureServer();
        $response = $this->get(route('modules.referrals.index'));
        $this->assertNotSame(200, $response->status());
    }

    public function test_admin_reward_journal_filters_results_without_changing_earned_history(): void
    {
        $server = $this->configureServer();
        $owner = Admin::factory()->create();
        $inviter = User::factory()->create();
        $invite = $this->invite($inviter, User::factory()->create());
        $row = $this->entitlement($invite, $server, $inviter, 20, 57);
        $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/grants?server='.$server->id.'&user='.$inviter->id.'&level=20&status=pending')
            ->assertOk()->assertSee(__('module-referrals::messages.reward_journal'))
            ->assertSee(__('module-referrals::messages.invitation_number', ['number' => $invite->id]));
        $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/grants?level=40')
            ->assertOk()->assertDontSee(__('module-referrals::messages.invitation_number', ['number' => $invite->id]));
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_item_icons_are_shared_by_account_rules_history_admin_rules_and_journal(): void
    {
        $root = storage_path('framework/testing/referral-icons-'.Str::uuid());
        File::ensureDirectoryExists($root.'/items/common');
        File::copy(base_path('modules/referrals/assets/module.webp'), $root.'/items/common/57.webp');
        config()->set('cms.game_assets.uploads_path', $root);

        try {
            $server = $this->configureServer();
            $user = User::factory()->create();
            $invite = $this->invite($user, User::factory()->create());
            $this->entitlement($invite, $server, $user, 20, 57);
            $icon = '/uploads/game-assets/items/common/57.webp';
            $account = $this->actingAs($user)->get(route('modules.referrals.index'))->assertOk();
            $account->assertSee($icon, false);
            $this->get(route('modules.referrals.index', ['tab' => 'history']))->assertOk()->assertSee($icon, false);
            $owner = Admin::factory()->create();
            $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/servers/'.$server->id)
                ->assertOk()->assertSee($icon, false);
            $this->get('/admin/extensions/referrals/grants')
                ->assertOk()->assertSee($icon, false);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_account_tabs_show_only_the_selected_section_and_preserve_server(): void
    {
        $server = $this->configureServer();
        $user = User::factory()->create();
        foreach (['invite', 'progress', 'history'] as $tab) {
            $response = $this->actingAs($user)->get(route('modules.referrals.index', ['server' => $server->id, 'tab' => $tab]));
            $response->assertOk()->assertViewHas('activeTab', $tab)->assertSee('data-referral-tabs', false);
            if ($tab === 'invite') {
                $response->assertSee('id="referral-link"', false);
            } else {
                $response->assertDontSee('id="referral-link"', false);
            }
            foreach (['invite', 'progress', 'history'] as $destination) {
                $response->assertSee(route('modules.referrals.index', ['server' => $server->id, 'tab' => $destination]));
            }
        }
        $this->get(route('modules.referrals.index', ['tab' => 'unexpected']))->assertOk()->assertViewHas('activeTab', 'invite');
    }

    public function test_history_filters_all_or_one_server_without_exposing_other_players_rewards(): void
    {
        $first = $this->configureServer();
        $second = $this->configureServer();
        $user = User::factory()->create();
        $invite = $this->invite($user, User::factory()->create());
        $one = $this->entitlement($invite, $first, $user, 20, 57);
        $two = $this->entitlement($invite, $second, $user, 30, 4037);
        $other = User::factory()->create();
        $foreign = $this->entitlement($this->invite($other, User::factory()->create()), $second, $other, 40, 57);
        $response = $this->actingAs($user)->get(route('modules.referrals.index', ['tab' => 'history', 'server' => 'all']))->assertOk();
        $this->assertSame(2, $response->viewData('rewards')->total());
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $response->viewData('rewards')->getCollection()->modelKeys());
        $this->assertArrayNotHasKey($foreign->id, $response->viewData('rewardItems'));
        $response->assertSee($first->nameFor())->assertSee($second->nameFor());
        $this->assertStringContainsString('server=all', $response->viewData('rewards')->url(2));
        $this->assertStringContainsString('tab=history', $response->viewData('rewards')->url(2));
        foreach ([$first, $second] as $server) {
            $filtered = $this->get(route('modules.referrals.index', ['tab' => 'history', 'server' => $server->id]))->assertOk();
            $this->assertSame(1, $filtered->viewData('rewards')->total());
            $this->assertSame($server->id, $filtered->viewData('rewards')->first()->game_server_id);
        }
    }

    public function test_invitation_explains_both_recipients_and_uses_inventory_server_heading(): void
    {
        $server = $this->configureServer();
        ReferralRule::query()->create([
            'game_server_id' => $server->id, 'audience' => 'invitee', 'level' => 10,
            'item_id' => 57, 'amount' => 1, 'enabled' => true,
        ]);
        $this->actingAs(User::factory()->create())->get(route('modules.referrals.index'))->assertOk()
            ->assertSee('account-section-heading reward-inventory-heading', false)
            ->assertSee(__('module-referrals::messages.you_receive'))
            ->assertSee(__('module-referrals::messages.friend_receives'))
            ->assertSee(__('module-referrals::messages.friend_level', ['level' => 10]))
            ->assertDontSee(__('module-referrals::messages.rules_current_notice'));
    }

    public function test_admin_journals_show_linked_accounts_and_filter_by_account_name(): void
    {
        $server = $this->configureServer();
        $inviter = User::factory()->create(['name' => 'InviterReadable']);
        $invitee = User::factory()->create(['name' => 'InviteeReadable']);
        $invite = $this->invite($inviter, $invitee);
        $this->entitlement($invite, $server, $inviter, 20, 57);
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->get('/admin/extensions/referrals/invites')->assertOk()
            ->assertSee('InviterReadable')->assertSee('InviteeReadable')->assertSee(route('admin.users.show', ['adminPath' => 'admin', 'user' => $inviter]));
        $this->get('/admin/extensions/referrals/grants?account=InviterRead')->assertOk()->assertSee('InviterReadable');
        $response = $this->get('/admin/extensions/referrals/grants?account=InviteeReadable')->assertOk();
        $this->assertSame(0, $response->viewData('rewards')->total());
    }

    private function configureServer(): GameServer
    {
        ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
        $server = GameServer::factory()->create();
        ReferralServerSetting::query()->create([
            'game_server_id' => $server->id, 'enabled' => true, 'checks_paused' => false,
            'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => true,
        ]);
        ReferralRule::query()->create([
            'game_server_id' => $server->id, 'audience' => 'inviter', 'level' => 20,
            'item_id' => 57, 'amount' => 2, 'enabled' => true,
        ]);

        return $server;
    }

    private function invite(User $inviter, User $invitee): ReferralInvite
    {
        $code = ReferralCode::query()->create([
            'user_id' => $inviter->id, 'code' => str_repeat(chr(65 + ($inviter->id % 26)), 40),
        ]);

        return ReferralInvite::query()->create([
            'referral_code_id' => $code->id,
            'inviter_user_id' => $inviter->id, 'invitee_user_id' => $invitee->id,
            'accepted_at' => now(), 'status' => 'active',
        ]);
    }

    private function entitlement(ReferralInvite $invite, GameServer $server, User $recipient, int $level, int $itemId): ReferralEntitlement
    {
        $snapshot = ReferralSnapshot::query()->create([
            'invite_id' => $invite->id, 'game_server_id' => $server->id, 'snapshotted_at' => now(),
        ]);

        return ReferralEntitlement::query()->create([
            'snapshot_id' => $snapshot->id, 'invite_id' => $invite->id,
            'game_server_id' => $server->id, 'recipient_user_id' => $recipient->id,
            'audience' => 'inviter', 'level' => $level, 'item_id' => $itemId,
            'amount' => 2, 'status' => 'pending',
        ]);
    }
}
