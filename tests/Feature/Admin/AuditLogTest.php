<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_requires_admin_authentication(): void
    {
        $this->get('/admin/logs')->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_open_log_list_and_details(): void
    {
        $admin = $this->createAdmin();
        $log = app(AuditLogger::class)->success(
            category: 'system',
            action: 'system.health_checked',
            actor: 'Система',
            target: 'Диагностика',
            details: ['status' => 'ok'],
            actorType: 'system',
        );

        $this->actingAs($admin, 'admin')
            ->get('/admin/logs')
            ->assertOk()
            ->assertSee('Журнал действий')
            ->assertSee('System Health Checked')
            ->assertSee('Диагностика');

        $this->actingAs($admin, 'admin')
            ->get('/admin/logs/'.$log?->id)
            ->assertOk()
            ->assertSee('Подробности')
            ->assertSee('status')
            ->assertSee('ok');
    }

    public function test_audit_logger_hides_sensitive_values_recursively(): void
    {
        $log = app(AuditLogger::class)->success(
            category: 'admin',
            action: 'settings.mail_updated',
            actor: 'Admin',
            target: 'Почта',
            details: [
                'smtp_password' => 'SecretPassword123',
                'totp' => '123456',
                'nested' => [
                    'reset_token' => 'TopSecretToken',
                    'recovery_code' => 'ABCDE-12345',
                    'host' => 'smtp.example.com',
                ],
            ],
            actorType: 'admin',
        );

        $details = $log?->fresh()->details ?? [];
        $encoded = json_encode($details, JSON_UNESCAPED_UNICODE);

        $this->assertSame('[REDACTED]', $details['smtp_password']);
        $this->assertSame('[REDACTED]', $details['totp']);
        $this->assertSame('[REDACTED]', $details['nested']['reset_token']);
        $this->assertSame('[REDACTED]', $details['nested']['recovery_code']);
        $this->assertSame('smtp.example.com', $details['nested']['host']);
        $this->assertStringNotContainsString('SecretPassword123', (string) $encoded);
        $this->assertStringNotContainsString('TopSecretToken', (string) $encoded);
        $this->assertStringNotContainsString('123456', (string) $encoded);
        $this->assertStringNotContainsString('ABCDE-12345', (string) $encoded);
    }

    public function test_settings_and_public_login_actions_are_written_to_audit_log(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/registration', [
                'registration_enabled' => '0',
                'email_verification_required' => '1',
            ])
            ->assertRedirect(route('admin.settings.registration'));

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'admin',
            'action' => 'settings.registration_updated',
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'result' => 'success',
        ]);

        auth('admin')->logout();

        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);

        $this->post('/login', [
            'login' => $user->name,
            'password' => 'Password123',
        ])->assertRedirect(route('account'));

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'user',
            'action' => 'auth.login',
            'actor_type' => 'user',
            'actor_id' => (string) $user->id,
            'result' => 'success',
        ]);
    }

    public function test_cleanup_command_supports_dry_run_and_real_deletion(): void
    {
        AuditLog::query()->create([
            'category' => 'system',
            'action' => 'old.event',
            'actor_type' => 'system',
            'actor_name' => 'Система',
            'result' => 'success',
            'created_at' => now()->subDays(120),
        ]);
        $oldAdminLogin = AdminLoginLog::query()->create([
            'email' => 'old@example.com',
            'ip_address' => '203.0.113.20',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);
        $oldAdminLogin->forceFill([
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ])->save();

        $recentAdminLogin = AdminLoginLog::query()->create([
            'email' => 'recent@example.com',
            'ip_address' => '203.0.113.21',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);
        $recentAdminLogin->forceFill([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ])->save();

        $options = [
            '--days' => 90,
            '--admin-login-days' => 30,
        ];

        $this->artisan('kaevcms:logs-clean', [...$options, '--dry-run' => true])
            ->assertExitCode(0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('admin_login_logs', ['email' => 'old@example.com']);

        $this->artisan('kaevcms:logs-clean', $options)
            ->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseMissing('admin_login_logs', ['email' => 'old@example.com']);
        $this->assertDatabaseHas('admin_login_logs', ['email' => 'recent@example.com']);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'system',
            'action' => 'audit.cleaned',
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
