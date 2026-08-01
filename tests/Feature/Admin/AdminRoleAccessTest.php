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
            ->assertSee('Редактор')
            ->assertSee('Аудитор')
            ->assertSee('без возможности что-либо изменить')
            ->assertSee('Полный доступ ко всей CMS')
            ->assertDontSee('Демонстрация');
    }

    public function test_administrator_cannot_create_or_take_over_a_more_privileged_read_role(): void
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
        $auditor = $this->createAdmin([
            'email' => 'auditor@example.com',
            'role' => AdminRole::Auditor,
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

        foreach ([AdminRole::Owner, AdminRole::Auditor] as $forbiddenRole) {
            $email = $forbiddenRole->value.'-forbidden@example.com';

            $this->actingAs($administrator, 'admin')
                ->post('/admin/administrators', [
                    'name' => 'Forbidden role',
                    'email' => $email,
                    'role' => $forbiddenRole->value,
                    'password' => 'SecurePassword123',
                    'password_confirmation' => 'SecurePassword123',
                ])
                ->assertSessionHasErrors('role');

            $this->assertDatabaseMissing('admins', ['email' => $email]);
        }

        $this->actingAs($administrator, 'admin')
            ->get('/admin/administrators/'.$auditor->id.'/edit')
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->put('/admin/administrators/'.$auditor->id.'/password', [
                'password' => 'AnotherSecurePassword123',
                'password_confirmation' => 'AnotherSecurePassword123',
            ])
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->patch('/admin/administrators/'.$auditor->id.'/status', ['is_active' => 0])
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->delete('/admin/administrators/'.$auditor->id.'/two-factor', [
                'current_password' => 'CorrectPassword123',
            ])
            ->assertForbidden();
    }

    public function test_administrator_can_change_working_settings_but_not_critical_security_or_updates(): void
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

        $this->actingAs($administrator, 'admin')
            ->get('/admin/settings/system/updates')
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

    public function test_auditor_can_view_trusted_sections_but_cannot_change_data(): void
    {
        $auditor = $this->createAdmin([
            'email' => 'auditor@example.com',
            'role' => AdminRole::Auditor,
        ]);
        $otherAdmin = $this->createAdmin([
            'email' => 'other@example.com',
            'role' => AdminRole::Administrator,
        ]);

        $this->actingAs($auditor, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Режим просмотра')
            ->assertSee('Новости')
            ->assertSee('Пользователи')
            ->assertSee('Настройки')
            ->assertSee('Журнал действий')
            ->assertSee('Модули')
            ->assertSee('data-auto-refresh="0"', false);

        foreach ([
            '/admin/account/security',
            '/admin/news',
            '/admin/pages',
            '/admin/themes',
            '/admin/account-themes',
            '/admin/users',
            '/admin/administrators',
            '/admin/reward-deliveries',
            '/admin/settings',
            '/admin/settings/admin-panel',
            '/admin/settings/notifications',
            '/admin/settings/registration',
            '/admin/settings/game-accounts',
            '/admin/settings/languages',
            '/admin/settings/security',
            '/admin/settings/game-server',
            '/admin/settings/login-server',
            '/admin/settings/mail',
            '/admin/settings/system',
            '/admin/settings/system/queue',
            '/admin/settings/system/updates',
            '/admin/logs',
            '/admin/modules',
        ] as $path) {
            $this->actingAs($auditor, 'admin')
                ->get($path)
                ->assertOk()
                ->assertSee('Режим просмотра');
        }

        $this->actingAs($auditor, 'admin')
            ->get('/admin/administrators/'.$otherAdmin->id.'/edit')
            ->assertOk()
            ->assertSee($otherAdmin->email)
            ->assertSee('Режим просмотра');

        foreach ([
            ['put', '/admin/settings', []],
            ['post', '/admin/news', []],
            ['post', '/admin/settings/system/queue/cleanup', []],
            ['post', '/admin/settings/system/updates', []],
        ] as [$method, $path, $data]) {
            $this->actingAs($auditor, 'admin')->{$method}($path, $data)->assertForbidden();
        }

        $this->actingAs($auditor, 'admin')
            ->put('/admin/administrators/'.$auditor->id, [
                'name' => 'Changed auditor',
                'email' => $auditor->email,
            ])
            ->assertForbidden();
    }

    public function test_auditor_cannot_bypass_server_permissions_through_livewire_actions(): void
    {
        $auditor = $this->createAdmin(['role' => AdminRole::Auditor]);

        $this->actingAs($auditor, 'admin');

        $this->assertServerSaveActionForbidden(app(GameServerManager::class));
        $this->assertServerSaveActionForbidden(app(LoginServerManager::class));
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

    private function assertServerSaveActionForbidden(GameServerManager|LoginServerManager $component): void
    {
        try {
            $component->save();
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('Expected the Livewire server mutation to be forbidden.');
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
