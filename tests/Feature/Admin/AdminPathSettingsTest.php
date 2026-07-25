<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AdminPathSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AdminPathSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_administrator_path_remains_admin(): void
    {
        $this->get('/admin/login')
            ->assertOk();

        $this->assertSame('/admin/login', route('admin.login', absolute: false));
        $this->assertSame('/admin', app(AdminPathSettings::class)->displayPath());
    }

    public function test_administrator_can_change_path_suffix_and_is_redirected_to_new_address(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->put('/admin/settings/admin-panel/admin-path', [
                'admin_path_suffix' => 'test01',
            ]);

        $response
            ->assertRedirect('/admin-test01/settings/admin-panel')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
            'value' => 'test01',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.admin_path_updated',
            'result' => 'success',
        ]);

        $audit = AuditLog::query()->where('action', 'settings.admin_path_updated')->firstOrFail();
        $this->assertSame(__('Administrator panel address changed'), $audit->actionLabel());

        $this->get('/admin/settings/admin-panel')->assertNotFound();
        $this->get('/admin-test01/settings/admin-panel')
            ->assertOk()
            ->assertSee('/admin-test01')
            ->assertSee('php artisan kaevcms:admin-path --reset')
            ->assertSee('php artisan kaevcms:admin-path test01')
            ->assertSee(__('After the change, the old administrator address will stop working. Save the new address so that you do not lose access to the site.'));

        $this->get('/language/en?return='.urlencode('/admin-test01/settings/admin-panel'))
            ->assertRedirect(url('/en'));

        $this->assertSame('/admin-test01/login', route('admin.login', absolute: false));
    }

    public function test_administrator_can_restore_default_path_by_clearing_suffix(): void
    {
        $admin = $this->createAdmin();
        $settings = app(AdminPathSettings::class);
        $settings->updateSuffix('secure01');
        URL::defaults(['adminPath' => $settings->path()]);

        $this->actingAs($admin, 'admin')
            ->put('/admin-secure01/settings/admin-panel/admin-path', [
                'admin_path_suffix' => '',
            ])
            ->assertRedirect('/admin/settings/admin-panel')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
            'value' => '',
        ]);
        $this->get('/admin-secure01/settings/admin-panel')->assertNotFound();
        $this->get('/admin/settings/admin-panel')->assertOk();
    }

    public function test_positional_route_parameters_keep_dynamic_administrator_prefix(): void
    {
        $settings = app(AdminPathSettings::class);
        $settings->updateSuffix('secure01');
        URL::defaults(['adminPath' => $settings->path()]);
        $user = User::factory()->create();

        $this->assertSame(
            '/admin-secure01/users/'.$user->getRouteKey(),
            route('admin.users.show', $user, absolute: false),
        );
    }

    public function test_dynamic_infrastructure_parameter_does_not_replace_controller_route_parameters(): void
    {
        $admin = $this->createAdmin();
        $settings = app(AdminPathSettings::class);
        $settings->updateSuffix('secure01');
        URL::defaults(['adminPath' => $settings->path()]);

        $this->actingAs($admin, 'admin')
            ->post('/admin-secure01/themes/default/activate')
            ->assertRedirect('/admin-secure01/themes')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'theme.active',
            'value' => 'default',
        ]);
    }

    public function test_invalid_suffix_is_rejected_without_changing_default_path(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/admin-panel')
            ->put('/admin/settings/admin-panel/admin-path', [
                'admin_path_suffix' => 'Тест_01',
            ])
            ->assertRedirect('/admin/settings/admin-panel')
            ->assertSessionHasErrors('admin_path_suffix');

        $this->assertDatabaseMissing('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
        ]);
        $this->get('/admin/login')->assertRedirect(route('admin.dashboard'));
    }

    public function test_legacy_system_admin_path_endpoint_is_not_registered(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/system/admin-path', [
                'admin_path_suffix' => 'legacy01',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
        ]);
    }

    public function test_artisan_command_can_show_change_and_reset_administrator_path(): void
    {
        $this->artisan('kaevcms:admin-path')
            ->expectsOutput('Current administrator panel address: /admin')
            ->assertSuccessful();

        $this->artisan('kaevcms:admin-path', ['suffix' => 'console01'])
            ->expectsOutput('Administrator panel address changed.')
            ->expectsOutput('Current address: /admin-console01')
            ->assertSuccessful();

        $this->assertDatabaseHas('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
            'value' => 'console01',
        ]);

        $this->artisan('kaevcms:admin-path', ['--reset' => true])
            ->expectsOutput('Administrator panel address reset.')
            ->expectsOutput('Current address: /admin')
            ->assertSuccessful();

        $this->assertDatabaseHas('cms_settings', [
            'key' => AdminPathSettings::SETTING_KEY,
            'value' => '',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Path Admin',
            'email' => 'path-admin@example.test',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }
}
