<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminThemeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_theme_management(): void
    {
        $this->get('/admin/themes')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_installed_themes(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/themes')
            ->assertOk()
            ->assertSee('Темы')
            ->assertSee('L2 Dark Classic')
            ->assertSee('Kaev Aurelia')
            ->assertSee('Дизайн административной панели от темы не зависит.')
            ->assertSee('data-theme-list', false)
            ->assertSee('class="admin-card-row admin-catalog-row theme-card theme-row', false)
            ->assertDontSee('class="theme-grid', false);
    }

    public function test_admin_can_activate_valid_theme(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/themes/default/activate')
            ->assertRedirect(route('admin.themes.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'theme.active',
            'value' => 'default',
        ]);
    }

    public function test_theme_activation_rejects_invalid_slug(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/themes/not-existing/activate')
            ->assertRedirect(route('admin.themes.index'))
            ->assertSessionHasErrors('theme');

        $this->assertDatabaseMissing('cms_settings', [
            'key' => 'theme.active',
            'value' => 'not-existing',
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }
}
