<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use RuntimeException;
use Tests\TestCase;

class DonationsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $modules = app(ModuleManager::class);
        $module = $modules->enable('donations');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_bundled_donations_is_valid_migrates_and_starts_hidden(): void
    {
        $module = app(ModuleManager::class)->inspect('donations');
        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible']);
        $this->assertSame('1.16.1', $module['version']);
        $this->assertTrue($module['enabled']);
        $this->assertSame([], $module['pending_migrations']);
        $this->assertTrue(Schema::hasTable('module_donation_settings'));
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'show_in_account' => false]);
        $this->assertContains('admin.module-pages.donations.index', array_column(app(ModuleNavigationRegistry::class)->adminLinks(), 'route'));
        $this->assertNotContains('modules.donations.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.donations.index'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.donations.settings.update'));
    }

    public function test_player_does_not_see_page_until_opted_in_and_never_gets_checkout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/modules/donations')->assertNotFound();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        app(DonationSettings::class)->updateVisibility(true, $owner);
        $this->actingAs($user)->get('/modules/donations')
            ->assertOk()
            ->assertSee(__('module-donations::messages.account_description'))
            ->assertSee('data-testid="donations-no-currencies"', false)
            ->assertDontSee('name="payment_amount"', false)
            ->assertDontSee('https://yoomoney.ru/quickpay/confirm');
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->actingAs($user)->post('/modules/donations')->assertStatus(405);
    }

    public function test_admin_permissions_and_visibility_update(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);

        $this->actingAs($editor, 'admin')->get('/admin/extensions/donations')->assertForbidden();
        $this->actingAs($editor, 'admin')->put('/admin/extensions/donations/settings', ['show_in_account' => '1'])->assertForbidden();
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/donations')->assertOk();
        $this->actingAs($auditor, 'admin')->put('/admin/extensions/donations/settings', ['show_in_account' => '1'])->assertForbidden();
        $this->actingAs($owner, 'admin')->get('/admin/extensions/donations')->assertOk();
        $this->actingAs($owner, 'admin')->put('/admin/extensions/donations/settings', ['show_in_account' => '1'])->assertRedirect();
        $this->assertTrue(app(DonationSettings::class)->isVisibleToPlayers());
        $this->actingAs($admin, 'admin')->put('/admin/extensions/donations/settings', ['show_in_account' => '0'])->assertRedirect();
        $this->assertFalse(app(DonationSettings::class)->isVisibleToPlayers());
        $this->assertSame(2, AuditLog::query()->where('action', 'donations.visibility_updated')->count());
    }

    public function test_invalid_settings_do_not_change_player_visibility(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/donations/settings', ['show_in_account' => 'invalid'])
            ->assertSessionHasErrors('show_in_account');
        $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'show_in_account' => false]);
    }

    public function test_service_rejects_editor_even_when_called_directly(): void
    {
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->expectException(AuthorizationException::class);
        app(DonationSettings::class)->updateVisibility(true, $editor);
    }

    public function test_missing_or_corrupt_settings_fail_closed(): void
    {
        DonationSetting::query()->whereKey(1)->delete();
        $this->assertFalse(app(DonationSettings::class)->isVisibleToPlayers());
        $user = User::factory()->create();
        $this->actingAs($user)->get('/modules/donations')->assertNotFound();
    }

    public function test_failed_audit_rolls_back_settings_change(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $audit = $this->mock(AuditLogger::class);
        $audit->shouldReceive('success')->once()->andReturn(null);
        try {
            app(DonationSettings::class)->updateVisibility(true, $owner);
            $this->fail('Expected failed audit to abort settings update.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('module_donation_settings', ['id' => 1, 'show_in_account' => false]);
        }
    }

    public function test_module_disable_does_not_modify_core_wallet_data(): void
    {
        $user = User::factory()->create();
        $walletId = $user->wallet()->value('id');
        app(ModuleManager::class)->disable('donations');
        $this->actingAs($user)->get('/modules/donations')->assertNotFound();
        $this->assertSame($walletId, $user->wallet()->value('id'));
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertTrue(Schema::hasTable('wallets'));
    }
}
