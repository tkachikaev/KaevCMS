<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleGameServerDependencyRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use Tests\TestCase;

class ReferralsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('referrals');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_first_install_creates_schema_with_disabled_settings_and_admin_navigation(): void
    {
        $module = app(ModuleManager::class)->inspect('referrals');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertSame([], $module['pending_migrations']);
        $this->assertTrue($module['enabled']);
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'referrals',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        foreach ([
            'module_referral_settings',
            'module_referral_codes',
            'module_referral_invites',
            'module_referral_server_settings',
            'module_referral_rules',
            'module_referral_snapshots',
            'module_referral_entitlements',
            'module_referral_progress',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertDatabaseHas('module_referral_settings', [
            'setting_key' => 'global',
            'enabled' => false,
            'invite_ttl_days' => 7,
        ]);
        $this->assertContains('admin.module-pages.referrals.index', array_column(app(ModuleNavigationRegistry::class)->adminLinks(), 'route'));
        $this->assertNotContains('modules.referrals.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.referrals.index'));
    }

    public function test_admin_overview_is_read_only_for_auditor_and_denied_to_editor(): void
    {
        $auditor = Admin::factory()->auditor()->create();
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);

        $this->actingAs($auditor, 'admin')
            ->get('/admin/extensions/referrals')
            ->assertOk()
            ->assertSee(__('module-referrals::messages.not_configured_title'));

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/referrals')
            ->assertForbidden();
    }

    public function test_invitation_belongs_to_one_invitee_and_has_independent_server_snapshots(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $code = ReferralCode::query()->create([
            'user_id' => $inviter->id,
            'code' => 'EXAMPLEONLYUNGUESSABLECODE0123456789012',
        ]);
        $invite = ReferralInvite::query()->create([
            'referral_code_id' => $code->id,
            'inviter_user_id' => $inviter->id,
            'invitee_user_id' => $invitee->id,
            'accepted_at' => now(),
        ]);
        $serverA = GameServer::factory()->create();
        $serverB = GameServer::factory()->create();

        ReferralSnapshot::query()->create([
            'invite_id' => $invite->id,
            'game_server_id' => $serverA->id,
            'snapshotted_at' => now(),
        ]);
        ReferralSnapshot::query()->create([
            'invite_id' => $invite->id,
            'game_server_id' => $serverB->id,
            'snapshotted_at' => now(),
        ]);
        $this->assertSame(2, ReferralSnapshot::query()->where('invite_id', $invite->id)->count());

        try {
            ReferralInvite::query()->create([
                'referral_code_id' => $code->id,
                'inviter_user_id' => $inviter->id,
                'invitee_user_id' => $invitee->id,
                'accepted_at' => now(),
            ]);
            $this->fail('A second referral relationship was accepted for the same user.');
        } catch (QueryException) {
            $this->assertSame(1, ReferralInvite::query()->where('invitee_user_id', $invitee->id)->count());
        }

        try {
            ReferralSnapshot::query()->create([
                'invite_id' => $invite->id,
                'game_server_id' => $serverB->id,
                'snapshotted_at' => now(),
            ]);
            $this->fail('A second rules snapshot for the same invite and server was accepted.');
        } catch (QueryException) {
            $this->assertSame(2, ReferralSnapshot::query()->where('invite_id', $invite->id)->count());
        }
    }

    public function test_game_server_deletion_is_blocked_when_references_exist(): void
    {
        $server = GameServer::factory()->create();
        $guard = app(ModuleGameServerDependencyRegistry::class);

        $this->assertFalse($guard->blocksDeletion($server));

        ReferralServerSetting::query()->create(['game_server_id' => $server->id]);
        $this->assertTrue($guard->blocksDeletion($server));
        $this->assertDatabaseHas('module_referral_server_settings', [
            'game_server_id' => $server->id,
            'enabled' => false,
            'inviter_rewards_enabled' => false,
            'invitee_rewards_enabled' => false,
        ]);
    }

    public function test_disabled_module_keeps_existing_schema_and_data(): void
    {
        $server = GameServer::factory()->create();
        ReferralServerSetting::query()->create(['game_server_id' => $server->id]);

        app(ModuleManager::class)->disable('referrals');

        $this->assertTrue(Schema::hasTable('module_referral_server_settings'));
        $this->assertSame(1, DB::table('module_referral_server_settings')->count());
        $this->assertDatabaseHas('cms_modules', ['id' => 'referrals', 'enabled' => false]);
    }
}
