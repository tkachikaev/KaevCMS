<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use App\Models\AuditLog;
use App\Models\CmsSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_or_change_security_settings(): void
    {
        $this->get('/admin/settings/security')->assertRedirect(route('admin.login'));
        $this->put('/admin/settings/security', [])->assertRedirect(route('admin.login'));
        $this->post('/admin/settings/security/logs/cleanup', [])->assertRedirect(route('admin.login'));
    }

    public function test_administrator_can_open_security_settings_and_see_safe_defaults(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/security')
            ->assertOk()
            ->assertSee('Безопасность')
            ->assertSee('Защита входа администратора')
            ->assertSee('10')
            ->assertSee('100')
            ->assertSee('90')
            ->assertSee('30')
            ->assertSee('Очистить устаревшие записи')
            ->assertSee('Удалять пока нечего. Записи аудита считаются устаревшими через 90 дней, а записи входа администраторов — через 30 дней.')
            ->assertSee('data-security-cleanup-disabled="1"', false);
    }

    public function test_administrator_can_save_safe_security_settings(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/security', [
                'login_ip_per_minute' => 12,
                'login_ip_per_hour' => 240,
                'login_max_attempts' => 7,
                'login_decay_minutes' => 5,
                'audit_retention_days' => 180,
                'admin_login_retention_days' => 60,
            ])
            ->assertRedirect(route('admin.settings.security'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'security.admin_login_ip_per_minute',
            'value' => '12',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'security.admin_login_decay_seconds',
            'value' => '300',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'security.audit_retention_days',
            'value' => '180',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.security_updated',
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/logs')
            ->assertOk()
            ->assertSee('180');
    }

    public function test_dangerous_security_values_are_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/security')
            ->put('/admin/settings/security', [
                'login_ip_per_minute' => 0,
                'login_ip_per_hour' => 1,
                'login_max_attempts' => 1,
                'login_decay_minutes' => 0,
                'audit_retention_days' => 1,
                'admin_login_retention_days' => 1,
            ])
            ->assertRedirect('/admin/settings/security')
            ->assertSessionHasErrors([
                'login_ip_per_minute',
                'login_ip_per_hour',
                'login_max_attempts',
                'login_decay_minutes',
                'audit_retention_days',
                'admin_login_retention_days',
            ]);

        $this->assertSame(0, CmsSetting::query()->where('key', 'like', 'security.%')->count());
    }

    public function test_hourly_ip_limit_cannot_be_lower_than_minute_limit(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/security', [
                'login_ip_per_minute' => 60,
                'login_ip_per_hour' => 30,
                'login_max_attempts' => 5,
                'login_decay_minutes' => 1,
                'audit_retention_days' => 90,
                'admin_login_retention_days' => 30,
            ])
            ->assertSessionHasErrors('login_ip_per_hour');
    }

    public function test_saved_ip_limit_is_used_by_administrator_login(): void
    {
        CmsSetting::query()->insert([
            [
                'key' => 'security.admin_login_ip_per_minute',
                'value' => '5',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'security.admin_login_ip_per_hour',
                'value' => '30',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', [
                'email' => "security{$attempt}@example.com",
                'password' => 'WrongPassword123',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/admin/login', [
            'email' => 'blocked@example.com',
            'password' => 'WrongPassword123',
        ])->assertStatus(429);

        $this->assertSame(5, AdminLoginLog::query()->count());
    }

    public function test_security_page_shows_recent_administrator_sign_in_history(): void
    {
        $admin = $this->createAdmin();
        AdminLoginLog::query()->create([
            'admin_id' => $admin->id,
            'email' => 'admin@example.com',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Test Browser 1.0',
            'successful' => true,
        ]);
        AdminLoginLog::query()->create([
            'email' => 'unknown@example.com',
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Bad Browser 2.0',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/security')
            ->assertOk()
            ->assertSee('Последние попытки входа администратора')
            ->assertSee('admin@example.com')
            ->assertSee('unknown@example.com')
            ->assertSee('Test Browser 1.0')
            ->assertSee('Неверный email или пароль')
            ->assertSee('Успешно')
            ->assertDontSee('Проверить без удаления');
    }

    public function test_cleanup_requires_current_administrator_password(): void
    {
        $admin = $this->createAdmin();
        $this->createOldLogs();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/security/logs/cleanup', [
                'current_password' => 'WrongPassword123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseHas('audit_logs', ['action' => 'security.old']);
        $this->assertDatabaseHas('admin_login_logs', ['email' => 'old-login@example.com']);
    }

    public function test_administrator_can_delete_only_expired_log_records(): void
    {
        $admin = $this->createAdmin();
        $this->createOldLogs();
        $recentAudit = AuditLog::query()->create([
            'category' => 'system',
            'action' => 'security.recent',
            'actor_type' => 'system',
            'actor_name' => 'System',
            'result' => 'success',
            'created_at' => now()->subDays(5),
        ]);
        $recentLogin = AdminLoginLog::query()->create([
            'email' => 'recent-login@example.com',
            'ip_address' => '203.0.113.92',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/security')
            ->assertOk()
            ->assertSee('data-security-cleanup-disabled="0"', false);

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/security/logs/cleanup', [
                'current_password' => 'CorrectPassword123',
            ])
            ->assertRedirect(route('admin.settings.security'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'security.old']);
        $this->assertDatabaseMissing('admin_login_logs', ['email' => 'old-login@example.com']);
        $this->assertDatabaseHas('audit_logs', ['id' => $recentAudit->id]);
        $this->assertDatabaseHas('admin_login_logs', ['id' => $recentLogin->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.logs_cleaned',
            'actor_id' => (string) $admin->id,
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'security.logs_last_cleaned_at',
        ]);
    }

    public function test_saved_retention_periods_control_manual_cleanup(): void
    {
        $admin = $this->createAdmin();
        CmsSetting::query()->insert([
            [
                'key' => 'security.audit_retention_days',
                'value' => '30',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'security.admin_login_retention_days',
                'value' => '7',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        AuditLog::query()->create([
            'category' => 'system',
            'action' => 'security.custom-retention',
            'actor_type' => 'system',
            'actor_name' => 'System',
            'result' => 'success',
            'created_at' => now()->subDays(40),
        ]);
        $login = AdminLoginLog::query()->create([
            'email' => 'custom-retention@example.com',
            'ip_address' => '203.0.113.93',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);
        $login->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/security/logs/cleanup', [
                'current_password' => 'CorrectPassword123',
            ])
            ->assertRedirect(route('admin.settings.security'));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'security.custom-retention']);
        $this->assertDatabaseMissing('admin_login_logs', ['email' => 'custom-retention@example.com']);
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

    private function createOldLogs(): void
    {
        AuditLog::query()->create([
            'category' => 'system',
            'action' => 'security.old',
            'actor_type' => 'system',
            'actor_name' => 'System',
            'result' => 'success',
            'created_at' => now()->subDays(120),
        ]);
        $oldLogin = AdminLoginLog::query()->create([
            'email' => 'old-login@example.com',
            'ip_address' => '203.0.113.91',
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);
        $oldLogin->forceFill([
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ])->save();
    }
}
