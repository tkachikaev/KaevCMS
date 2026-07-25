<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AdminLoginLog;
use App\Services\AdminTwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_two_factor_requires_challenge_before_admin_is_authenticated(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = $service->generateSecret();
        $admin = $this->createAdminWithTwoFactor($secret);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ])->assertRedirect(route('admin.two-factor.challenge'));

        $this->assertGuest('admin');
        $this->assertSame(0, AdminLoginLog::query()->count());

        $this->get('/admin/two-factor-challenge')
            ->assertOk()
            ->assertSee('Двухфакторная аутентификация');

        $this->post('/admin/two-factor-challenge', [
            'code' => $service->codeAt($secret, now()->timestamp),
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('admin_login_logs', [
            'admin_id' => $admin->id,
            'successful' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_succeeded']);
    }

    public function test_invalid_two_factor_code_is_rejected_and_logged(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $admin = $this->createAdminWithTwoFactor($service->generateSecret());

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ]);

        $invalidCode = $service->codeAt((string) $admin->two_factor_secret, now()->timestamp) === '000000' ? '000001' : '000000';

        $this->post('/admin/two-factor-challenge', ['code' => $invalidCode])
            ->assertSessionHasErrors('code');

        $this->assertGuest('admin');
        $this->assertDatabaseHas('admin_login_logs', [
            'admin_id' => $admin->id,
            'successful' => false,
            'failure_reason' => 'invalid_two_factor',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_failed']);
    }

    public function test_recovery_code_can_be_used_once(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = $service->generateSecret();
        $recoveryCode = 'ABCDE-12345';
        $admin = $this->createAdminWithTwoFactor($secret, [$recoveryCode]);

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ]);

        $this->post('/admin/two-factor-challenge', ['code' => $recoveryCode])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame([], $admin->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_recovery_used']);

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword123',
        ])->assertRedirect(route('admin.two-factor.challenge'));
        $this->post('/admin/two-factor-challenge', ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        config()->set('cms.admin.two_factor_max_attempts_per_minute', 5);
        config()->set('cms.admin.two_factor_max_attempts_per_hour', 20);
        $service = app(AdminTwoFactorAuthentication::class);
        $admin = $this->createAdminWithTwoFactor($service->generateSecret());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->post('/admin/login', [
                'email' => $admin->email,
                'password' => 'CorrectPassword123',
            ]);

        $invalidCode = $service->codeAt((string) $admin->two_factor_secret, now()->timestamp) === '000000' ? '000001' : '000000';

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
                ->post('/admin/two-factor-challenge', ['code' => $invalidCode])
                ->assertSessionHasErrors('code');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->post('/admin/two-factor-challenge', ['code' => $invalidCode])
            ->assertStatus(429);

        $this->assertSame(5, AdminLoginLog::query()->where('failure_reason', 'invalid_two_factor')->count());
    }

    public function test_expired_challenge_returns_to_login(): void
    {
        $admin = $this->createAdmin();

        $this->withSession([
            'admin_two_factor_challenge' => [
                'admin_id' => $admin->id,
                'email' => $admin->email,
                'remember' => false,
                'expires_at' => now()->subMinute()->timestamp,
            ],
        ])->get('/admin/two-factor-challenge')->assertRedirect(route('admin.login'));
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

    /** @param list<string> $recoveryCodes */
    private function createAdminWithTwoFactor(string $secret, array $recoveryCodes = []): Admin
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $admin = $this->createAdmin();
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $service->hashRecoveryCodes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }
}
