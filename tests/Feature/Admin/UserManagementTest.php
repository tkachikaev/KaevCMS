<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_requires_administrator_authentication(): void
    {
        $this->get('/admin/users')->assertRedirect(route('admin.login'));
    }

    public function test_administrator_can_search_and_filter_cms_users(): void
    {
        $admin = $this->createAdmin();
        $verified = $this->createUser([
            'name' => 'verified_player',
            'email' => 'verified@example.com',
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);
        $inactive = $this->createUser([
            'name' => 'blocked_player',
            'email' => 'blocked@example.com',
            'is_active' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Пользователи')
            ->assertSee($verified->name)
            ->assertSee($inactive->name)
            ->assertSee($verified->last_login_at->format('d.m.Y H:i'));

        $this->actingAs($admin, 'admin')
            ->get('/admin/users?q=verified&status=active&verification=verified')
            ->assertOk()
            ->assertSee($verified->email)
            ->assertDontSee($inactive->email);

        $this->actingAs($admin, 'admin')
            ->get('/admin/users?status=inactive&verification=unverified')
            ->assertOk()
            ->assertSee($inactive->email)
            ->assertDontSee($verified->email);
    }

    public function test_user_detail_contains_account_information_and_related_audit_events(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser([
            'name' => 'history_player',
            'email' => 'history@example.com',
            'email_verified_at' => now(),
            'last_login_at' => now(),
        ]);

        app(AuditLogger::class)->success(
            category: 'user',
            action: 'user.email_verified',
            actor: $user,
            target: $user,
        );

        $this->actingAs($admin, 'admin')
            ->get('/admin/users/'.$user->id)
            ->assertOk()
            ->assertSee('Пользователь '.$user->name)
            ->assertSee($user->email)
            ->assertSee('Email подтверждён')
            ->assertSee('Подтверждён email')
            ->assertSee('Игровые данные');
    }

    public function test_administrator_can_disable_and_enable_user_without_deleting_account(): void
    {
        $admin = $this->createAdmin();
        $user = $this->createUser([
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->patch('/admin/users/'.$user->id.'/status', ['is_active' => 0])
            ->assertRedirect();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.disabled',
            'target_type' => 'user',
            'target_id' => (string) $user->id,
        ]);

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));

        $this->post('/login', [
            'login' => $user->name,
            'password' => 'Password123',
        ])->assertSessionHasErrors('login');
        $this->assertGuest('web');

        $this->actingAs($user->fresh(), 'web')
            ->get('/account')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Учётная запись отключена администратором.');
        $this->assertGuest('web');

        $this->actingAs($admin, 'admin')
            ->patch('/admin/users/'.$user->id.'/status', ['is_active' => 1])
            ->assertRedirect();

        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.enabled']);
    }

    public function test_successful_public_login_updates_last_login_date(): void
    {
        $user = $this->createUser([
            'email_verified_at' => now(),
            'last_login_at' => null,
        ]);

        $this->post('/login', [
            'login' => $user->name,
            'password' => 'Password123',
        ])->assertRedirect(route('account'));

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_administrator_can_resend_verification_and_password_reset_emails(): void
    {
        Notification::fake();
        $this->configureReadyMail();

        $admin = $this->createAdmin();
        $user = $this->createUser([
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/'.$user->id.'/verification')
            ->assertRedirect()
            ->assertSessionHas('status', 'Письмо подтверждения отправлено повторно.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.verification_resent',
            'target_id' => (string) $user->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/'.$user->id.'/password-reset')
            ->assertRedirect()
            ->assertSessionHas('status', 'Ссылка восстановления пароля отправлена пользователю.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.password_reset_sent',
            'target_id' => (string) $user->id,
        ]);
    }

    private function configureReadyMail(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => null,
            'from_address' => 'no-reply@example.com',
            'from_name' => 'L2 Test',
            'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
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

    private function createUser(array $attributes = []): User
    {
        $managed = array_intersect_key($attributes, array_flip([
            'email_verified_at',
            'is_active',
            'last_login_at',
        ]));
        unset(
            $attributes['email_verified_at'],
            $attributes['is_active'],
            $attributes['last_login_at'],
        );

        $user = User::query()->create(array_merge([
            'name' => 'player_'.strtolower(str()->random(8)),
            'email' => strtolower(str()->random(8)).'@example.com',
            'password' => Hash::make('Password123'),
        ], $attributes));

        if ($managed !== []) {
            $user->forceFill($managed)->save();
        }

        return $user->refresh();
    }
}
