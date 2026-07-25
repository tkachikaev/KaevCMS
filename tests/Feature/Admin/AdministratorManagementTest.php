<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Services\AdminTwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_section_requires_authentication(): void
    {
        $this->get('/admin/administrators')->assertRedirect(route('admin.login'));
    }

    public function test_administrator_can_open_list_with_creation_and_last_login_dates(): void
    {
        $current = $this->createAdmin([
            'name' => 'Main Admin',
            'last_login_at' => now(),
        ]);

        $this->actingAs($current, 'admin')
            ->get('/admin/administrators')
            ->assertOk()
            ->assertSee('Администраторы')
            ->assertSee('Main Admin')
            ->assertSee('Текущая учётная запись')
            ->assertSee($current->created_at->format('d.m.Y H:i'))
            ->assertSee($current->last_login_at->format('d.m.Y H:i'));
    }

    public function test_administrator_can_create_another_administrator(): void
    {
        $current = $this->createAdmin();

        $this->actingAs($current, 'admin')
            ->post('/admin/administrators', [
                'name' => 'Second Admin',
                'email' => 'SECOND@EXAMPLE.COM',
                'password' => 'SecurePassword123',
                'password_confirmation' => 'SecurePassword123',
            ])
            ->assertRedirect(route('admin.administrators.index'));

        $created = Admin::query()->where('email', 'second@example.com')->firstOrFail();

        $this->assertSame('Second Admin', $created->name);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('SecurePassword123', $created->password));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.created',
            'target_type' => 'admin',
            'target_id' => (string) $created->id,
        ]);
    }

    public function test_administrator_can_update_profile_and_another_administrators_password(): void
    {
        $current = $this->createAdmin();
        $second = $this->createAdmin([
            'name' => 'Second Admin',
            'email' => 'second@example.com',
        ]);

        $this->actingAs($current, 'admin')
            ->put('/admin/administrators/'.$second->id, [
                'name' => 'Updated Admin',
                'email' => 'UPDATED@EXAMPLE.COM',
            ])
            ->assertRedirect(route('admin.administrators.edit', $second));

        $this->actingAs($current, 'admin')
            ->put('/admin/administrators/'.$second->id.'/password', [
                'password' => 'AnotherPassword123',
                'password_confirmation' => 'AnotherPassword123',
            ])
            ->assertRedirect(route('admin.administrators.edit', $second));

        $second->refresh();

        $this->assertSame('Updated Admin', $second->name);
        $this->assertSame('updated@example.com', $second->email);
        $this->assertTrue(Hash::check('AnotherPassword123', $second->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.password_changed']);
    }

    public function test_current_administrator_must_confirm_current_password_before_changing_it(): void
    {
        $current = $this->createAdmin();

        $this->actingAs($current, 'admin')
            ->put('/admin/administrators/'.$current->id.'/password', [
                'current_password' => 'WrongPassword123',
                'password' => 'NewSecurePassword123',
                'password_confirmation' => 'NewSecurePassword123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('CorrectPassword123', $current->fresh()->password));

        $this->actingAs($current, 'admin')
            ->put('/admin/administrators/'.$current->id.'/password', [
                'current_password' => 'CorrectPassword123',
                'password' => 'NewSecurePassword123',
                'password_confirmation' => 'NewSecurePassword123',
            ])
            ->assertRedirect(route('admin.administrators.edit', $current));

        $this->assertTrue(Hash::check('NewSecurePassword123', $current->fresh()->password));
    }

    public function test_current_or_last_active_administrator_cannot_be_disabled(): void
    {
        $current = $this->createAdmin();

        $this->actingAs($current, 'admin')
            ->patch('/admin/administrators/'.$current->id.'/status', ['is_active' => 0])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($current->fresh()->is_active);

        $inactive = $this->createAdmin([
            'name' => 'Inactive Admin',
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);

        $this->actingAs($current, 'admin')
            ->patch('/admin/administrators/'.$inactive->id.'/status', ['is_active' => 1])
            ->assertRedirect();

        $this->assertTrue($inactive->fresh()->is_active);

        $this->actingAs($current, 'admin')
            ->patch('/admin/administrators/'.$inactive->id.'/status', ['is_active' => 0])
            ->assertRedirect();

        $this->assertFalse($inactive->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.activated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.deactivated']);
    }

    public function test_disabled_authenticated_administrator_is_removed_from_admin_session(): void
    {
        $administrator = $this->createAdmin(['is_active' => false]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin')
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('status', 'Учётная запись администратора отключена.');

        $this->assertGuest('admin');
    }

    public function test_administrator_list_and_details_show_two_factor_status(): void
    {
        $current = $this->createAdmin();
        $service = app(AdminTwoFactorAuthentication::class);
        $second = $this->createAdmin([
            'name' => 'Protected Admin',
            'email' => 'protected@example.com',
        ]);
        $second->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($current, 'admin')
            ->get('/admin/administrators')
            ->assertOk()
            ->assertSee('2FA')
            ->assertSee('Protected Admin')
            ->assertSee('Включена');

        $this->actingAs($current, 'admin')
            ->get('/admin/administrators/'.$second->id.'/edit')
            ->assertOk()
            ->assertSee('Двухфакторная аутентификация')
            ->assertSee('Сбросить 2FA');
    }

    public function test_administrator_can_reset_another_administrators_two_factor(): void
    {
        $current = $this->createAdmin();
        $service = app(AdminTwoFactorAuthentication::class);
        $target = $this->createAdmin([
            'name' => 'Protected Admin',
            'email' => 'protected@example.com',
        ]);
        $target->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => $service->hashRecoveryCodes(['ABCDE-12345']),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $oldVersion = $target->session_version;

        $this->actingAs($current, 'admin')
            ->delete('/admin/administrators/'.$target->id.'/two-factor', [
                'current_password' => 'CorrectPassword123',
            ])->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->twoFactorEnabled());
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertSame($oldVersion + 1, $target->session_version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.2fa_reset',
            'target_id' => (string) $target->id,
        ]);

        $this->actingAs($target, 'admin')
            ->withSession(['admin_session_version' => $oldVersion])
            ->get('/admin')
            ->assertRedirect(route('admin.login'));
    }

    public function test_wrong_password_cannot_reset_another_administrators_two_factor(): void
    {
        $current = $this->createAdmin();
        $service = app(AdminTwoFactorAuthentication::class);
        $target = $this->createAdmin([
            'email' => 'protected@example.com',
        ]);
        $target->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($current, 'admin')
            ->delete('/admin/administrators/'.$target->id.'/two-factor', [
                'current_password' => 'WrongPassword123',
            ])->assertSessionHasErrors('current_password');

        $this->assertTrue($target->fresh()->twoFactorEnabled());
    }

    public function test_console_command_can_disable_two_factor(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $admin = $this->createAdmin();
        $admin->forceFill([
            'two_factor_secret' => $service->generateSecret(),
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('kaevcms:admin-2fa:disable', [
            'email' => $admin->email,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFalse($admin->fresh()->twoFactorEnabled());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.2fa_reset_console',
            'actor_type' => 'system',
        ]);
    }

    private function createAdmin(array $attributes = []): Admin
    {
        return Admin::query()->create(array_merge([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ], $attributes));
    }
}
