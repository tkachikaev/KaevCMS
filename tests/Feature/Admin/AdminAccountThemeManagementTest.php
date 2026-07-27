<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_account_theme_management(): void
    {
        $this->get('/admin/account-themes')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_installed_account_themes(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/account-themes')
            ->assertOk()
            ->assertSee('Шаблоны личного кабинета')
            ->assertSee('L2 Obsidian Luxury')
            ->assertSee('Kaev Aurelia Account')
            ->assertSee('Дизайн личного кабинета не зависит от публичной темы сайта и административной панели.')
            ->assertSee('data-account-theme-list', false)
            ->assertSee('class="admin-card-row admin-catalog-row theme-card theme-row', false)
            ->assertDontSee('class="theme-grid', false);
    }

    public function test_admin_can_activate_valid_account_theme_without_changing_public_theme(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/account-themes/luxury/activate')
            ->assertRedirect(route('admin.account-themes.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'account_theme.active',
            'value' => 'luxury',
        ]);
        $this->assertDatabaseMissing('cms_settings', [
            'key' => 'theme.active',
        ]);
    }

    public function test_account_theme_activation_rejects_unknown_slug(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/account-themes/not-existing/activate')
            ->assertRedirect(route('admin.account-themes.index'))
            ->assertSessionHasErrors('theme');

        $this->assertDatabaseMissing('cms_settings', [
            'key' => 'account_theme.active',
            'value' => 'not-existing',
        ]);
    }

    public function test_player_account_is_rendered_from_the_active_account_theme(): void
    {
        $user = User::factory()->create([
            'name' => 'Theme Player',
            'email' => 'theme-player@example.test',
            'locale' => 'ru',
        ]);

        $theme = app(AccountThemeManager::class)->inspect('luxury');
        $this->assertTrue($theme['valid']);
        $this->assertTrue($theme['compatible']);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('account-themes/luxury/assets/css/app.css', false)
            ->assertSee('assets/account/js/navigation.js', false)
            ->assertDontSee('account-themes/luxury/assets/js/navigation.js', false)
            ->assertSee('L2 Obsidian Luxury')
            ->assertDontSee('assets/account/css/app.css', false);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin-account-theme@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }
}
