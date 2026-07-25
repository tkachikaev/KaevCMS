<?php

namespace Tests\Feature\Admin;

use App\Auth\AdminRole;
use App\Livewire\Admin\GameServerManager;
use App\Livewire\Admin\LoginServerManager;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AdminRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_unspecified_admin_accounts_default_to_owner(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Default Owner',
            'email' => 'default-owner@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);

        $this->assertSame(AdminRole::Owner, $admin->role);
        $this->assertTrue($admin->isOwner());
    }

    public function test_owner_can_choose_every_role_and_role_descriptions_are_visible(): void
    {
        $owner = $this->createAdmin();

        $this->actingAs($owner, 'admin')
            ->get('/admin/administrators/create')
            ->assertOk()
            ->assertSee('Владелец')
            ->assertSee('Администратор')
            ->assertDontSee('Модератор')
            ->assertSee('Редактор')
            ->assertSee('Полный доступ ко всей CMS');
    }

    public function test_administrator_can_manage_non_owners_but_cannot_manage_or_create_an_owner(): void
    {
        $owner = $this->createAdmin(['email' => 'owner@example.com']);
        $administrator = $this->createAdmin([
            'email' => 'administrator@example.com',
            'role' => AdminRole::Administrator,
        ]);
        $editor = $this->createAdmin([
            'email' => 'editor@example.com',
            'role' => AdminRole::Editor,
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin/administrators/'.$owner->id.'/edit')
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->put('/admin/administrators/'.$editor->id, [
                'name' => 'Promoted Editor',
                'email' => $editor->email,
                'role' => AdminRole::Administrator->value,
            ])
            ->assertRedirect(route('admin.administrators.edit', $editor));

        $this->assertSame(AdminRole::Administrator, $editor->fresh()->role);

        $this->actingAs($administrator, 'admin')
            ->post('/admin/administrators', [
                'name' => 'Forbidden Owner',
                'email' => 'forbidden-owner@example.com',
                'role' => AdminRole::Owner->value,
                'password' => 'SecurePassword123',
                'password_confirmation' => 'SecurePassword123',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('admins', ['email' => 'forbidden-owner@example.com']);
    }

    public function test_administrator_can_change_working_settings_but_not_critical_security_or_admin_path(): void
    {
        $administrator = $this->createAdmin(['role' => AdminRole::Administrator]);

        $this->actingAs($administrator, 'admin')
            ->put('/admin/settings/admin-panel/monitoring', ['refresh_interval_seconds' => 120])
            ->assertRedirect();

        $this->actingAs($administrator, 'admin')
            ->put('/admin/settings/security', [])
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->put('/admin/settings/admin-panel/admin-path', ['admin_path_suffix' => 'private'])
            ->assertForbidden();
    }

    public function test_editor_cannot_bypass_server_permissions_through_livewire_actions(): void
    {
        $editor = $this->createAdmin(['role' => AdminRole::Editor]);

        $this->actingAs($editor, 'admin');

        $this->assertServerCreateActionForbidden(app(GameServerManager::class));
        $this->assertServerCreateActionForbidden(app(LoginServerManager::class));
    }

    public function test_editor_only_has_dashboard_content_and_own_profile_access(): void
    {
        $editor = $this->createAdmin(['role' => AdminRole::Editor]);

        $this->actingAs($editor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Новости')
            ->assertDontSee('Почта')
            ->assertDontSee('Настройки')
            ->assertDontSee('Пользователи');

        $this->actingAs($editor, 'admin')->get('/admin/news')->assertOk();
        $this->actingAs($editor, 'admin')->get('/admin/pages')->assertOk();
        $this->actingAs($editor, 'admin')
            ->postJson('/admin/server-monitor/status')
            ->assertOk()
            ->assertJsonPath('monitor.game_servers', [])
            ->assertJsonPath('monitor.login_servers', []);
        $this->actingAs($editor, 'admin')->get('/admin/users')->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/settings')->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/settings/mail')->assertForbidden();
        $this->actingAs($editor, 'admin')->get('/admin/administrators/'.$editor->id.'/edit')->assertOk();
    }

    public function test_last_active_owner_cannot_be_downgraded_or_disabled(): void
    {
        $owner = $this->createAdmin(['email' => 'owner@example.com']);
        $administrator = $this->createAdmin([
            'email' => 'administrator@example.com',
            'role' => AdminRole::Administrator,
        ]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/administrators/'.$owner->id, [
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => AdminRole::Administrator->value,
            ])
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->patch('/admin/administrators/'.$owner->id.'/status', ['is_active' => 0])
            ->assertSessionHasErrors('is_active');

        $this->assertSame(AdminRole::Owner, $owner->fresh()->role);
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(AdminRole::Administrator, $administrator->fresh()->role);
    }

    public function test_role_change_invalidates_previous_sessions(): void
    {
        $owner = $this->createAdmin(['email' => 'owner@example.com']);
        $target = $this->createAdmin([
            'email' => 'target@example.com',
            'role' => AdminRole::Administrator,
        ]);
        $oldVersion = $target->session_version;

        $this->actingAs($owner, 'admin')
            ->put('/admin/administrators/'.$target->id, [
                'name' => $target->name,
                'email' => $target->email,
                'role' => AdminRole::Editor->value,
            ])
            ->assertRedirect(route('admin.administrators.edit', $target));

        $target->refresh();
        $this->assertSame(AdminRole::Editor, $target->role);
        $this->assertSame($oldVersion + 1, $target->session_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.role_changed']);

        $this->actingAs($target, 'admin')
            ->withSession(['admin_session_version' => $oldVersion])
            ->get('/admin')
            ->assertRedirect(route('admin.login'));
    }

    private function assertServerCreateActionForbidden(GameServerManager|LoginServerManager $component): void
    {
        try {
            $component->create();
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('Expected the Livewire server action to be forbidden.');
    }

    private function createAdmin(array $attributes = []): Admin
    {
        return Admin::query()->create(array_merge([
            'name' => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'role' => AdminRole::Owner,
        ], $attributes));
    }
}
