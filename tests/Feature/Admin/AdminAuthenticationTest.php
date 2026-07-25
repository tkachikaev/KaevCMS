<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_dashboard(): void
    {
        $this->get('/admin')->assertRedirect(route('admin.login'));
    }

    public function test_admin_login_page_is_available(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Вход администратора')
            ->assertSee('login-brand-top', false)
            ->assertSee('login-brand-copy', false);
    }

    public function test_new_administrator_has_initial_session_version_in_memory(): void
    {
        $admin = $this->createAdmin();

        $this->assertSame(1, $admin->session_version);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk();
    }

    public function test_active_admin_can_login(): void
    {
        $admin = $this->createAdmin();

        $this->post('/admin/login', [
            'email' => 'ADMIN@example.com',
            'password' => 'CorrectPassword123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('admin_login_logs', [
            'admin_id' => $admin->id,
            'successful' => true,
        ]);
    }

    public function test_successful_admin_login_rehashes_a_legacy_bcrypt_password_to_argon2id(): void
    {
        if (! in_array('argon2id', password_algos(), true)) {
            $this->markTestSkipped('Argon2id is unavailable in this PHP build.');
        }

        config()->set('hashing.driver', 'argon2id');
        config()->set('hashing.argon.verify', false);
        app('hash')->forgetDrivers();

        $admin = $this->createAdmin();
        $legacyHash = password_hash('CorrectPassword123', PASSWORD_BCRYPT, ['cost' => 4]);

        DB::table('admins')
            ->where('id', $admin->getKey())
            ->update(['password' => $legacyHash]);

        $this->assertSame('bcrypt', password_get_info($admin->fresh()->password)['algoName']);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertSame('argon2id', password_get_info($admin->fresh()->password)['algoName']);
    }

    public function test_invalid_password_is_rejected_and_logged(): void
    {
        $admin = $this->createAdmin();

        $this->from('/admin/login')->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'WrongPassword123',
        ])->assertRedirect(route('admin.login'))->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_login_logs', [
            'admin_id' => $admin->id,
            'successful' => false,
            'failure_reason' => 'invalid_credentials',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_failed',
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'result' => 'failed',
        ]);
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = $this->createAdmin(['is_active' => false]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_login_logs', [
            'admin_id' => $admin->id,
            'successful' => false,
            'failure_reason' => 'inactive',
        ]);
    }

    public function test_admin_can_logout(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/logout')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $admin = $this->createAdmin();

        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', [
                'email' => $admin->email,
                'password' => 'WrongPassword123',
            ]);
        }

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertSame(5, AdminLoginLog::query()->count());
        $this->assertDatabaseMissing('admin_login_logs', [
            'failure_reason' => 'throttled',
        ]);
    }

    public function test_ip_limit_cannot_be_bypassed_by_changing_email(): void
    {
        config()->set('cms.admin.login_ip_max_attempts_per_minute', 5);
        config()->set('cms.admin.login_ip_max_attempts_per_hour', 30);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/admin/login', [
                'email' => "unknown{$attempt}@example.com",
                'password' => 'WrongPassword123',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/admin/login', [
            'email' => 'another-address@example.com',
            'password' => 'WrongPassword123',
        ])->assertStatus(429);

        $this->assertSame(5, AdminLoginLog::query()->count());
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'auth.login_failed')
            ->count());
    }

    public function test_hourly_ip_limit_is_also_applied(): void
    {
        config()->set('cms.admin.login_ip_max_attempts_per_minute', 5);
        config()->set('cms.admin.login_ip_max_attempts_per_hour', 30);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);

        $attempt = 0;

        foreach (range(1, 6) as $batch) {
            foreach (range(1, 5) as $request) {
                $attempt++;

                $this->post('/admin/login', [
                    'email' => "hourly{$attempt}@example.com",
                    'password' => 'WrongPassword123',
                ])->assertSessionHasErrors('email');
            }

            // Start a fresh minute window while keeping the same hourly window.
            $this->travel(61)->seconds();
        }

        $this->post('/admin/login', [
            'email' => 'hourly-limit@example.com',
            'password' => 'WrongPassword123',
        ])->assertStatus(429);

        $this->assertSame(30, AdminLoginLog::query()->count());
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
