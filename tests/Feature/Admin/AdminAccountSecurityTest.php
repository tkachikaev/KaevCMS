<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Services\AdminTwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_security_requires_authentication(): void
    {
        $this->get('/admin/account/security')->assertRedirect(route('admin.login'));
    }

    public function test_account_security_page_shows_personal_two_factor_status(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/account/security')
            ->assertOk()
            ->assertSee('Безопасность аккаунта')
            ->assertSee('2FA отключена')
            ->assertSee('Включить 2FA');
    }

    public function test_administrator_can_start_and_confirm_two_factor_setup(): void
    {
        $admin = $this->createAdmin();
        $service = app(AdminTwoFactorAuthentication::class);

        $this->actingAs($admin, 'admin')
            ->post('/admin/account/security/two-factor/setup', [
                'current_password' => 'CorrectPassword123',
            ])->assertRedirect(route('admin.account.security'))
            ->assertSessionHas('admin_two_factor_setup');

        $secret = $service->generateSecret();
        $oldVersion = $admin->session_version;
        $setup = [
            'secret' => Crypt::encryptString($secret),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];

        $this->actingAs($admin, 'admin')
            ->withSession(['admin_two_factor_setup' => $setup])
            ->post('/admin/account/security/two-factor/confirm', [
                'code' => $service->codeAt($secret, now()->timestamp),
            ])->assertRedirect(route('admin.account.security'))
            ->assertSessionHas('admin_two_factor_recovery_codes');

        $admin->refresh();
        $this->assertTrue($admin->twoFactorEnabled());
        $this->assertSame($oldVersion + 1, $admin->session_version);
        $this->assertSame($admin->session_version, session('admin_session_version'));
        $this->assertNotSame($secret, $admin->getRawOriginal('two_factor_secret'));
        $this->assertCount(8, $admin->two_factor_recovery_codes);
        $this->assertJsonIsNotDirectlyStored((string) $admin->getRawOriginal('two_factor_recovery_codes'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_enabled']);
    }

    public function test_wrong_current_password_cannot_start_setup(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/account/security/two-factor/setup', [
                'current_password' => 'WrongPassword123',
            ])->assertSessionHasErrors('current_password');

        $this->assertFalse($admin->fresh()->twoFactorEnabled());
    }

    public function test_administrator_can_regenerate_recovery_codes(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = $service->generateSecret();
        $admin = $this->createAdminWithTwoFactor($secret, ['OLD11-OLD22']);
        $oldHashes = $admin->two_factor_recovery_codes;

        $this->actingAs($admin, 'admin')
            ->post('/admin/account/security/two-factor/recovery-codes', [
                'current_password' => 'CorrectPassword123',
                'code' => $service->codeAt($secret, now()->timestamp),
            ])->assertRedirect(route('admin.account.security'))
            ->assertSessionHas('admin_two_factor_recovery_codes');

        $admin->refresh();
        $this->assertCount(8, $admin->two_factor_recovery_codes);
        $this->assertNotSame($oldHashes, $admin->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_recovery_regenerated']);
    }

    public function test_administrator_can_disable_own_two_factor_and_keep_current_session(): void
    {
        $service = app(AdminTwoFactorAuthentication::class);
        $secret = $service->generateSecret();
        $admin = $this->createAdminWithTwoFactor($secret);
        $oldVersion = $admin->session_version;

        $this->actingAs($admin, 'admin')
            ->delete('/admin/account/security/two-factor', [
                'current_password' => 'CorrectPassword123',
                'code' => $service->codeAt($secret, now()->timestamp),
            ])->assertRedirect(route('admin.account.security'));

        $admin->refresh();
        $this->assertFalse($admin->twoFactorEnabled());
        $this->assertSame($oldVersion + 1, $admin->session_version);
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_disabled']);
    }

    private function assertJsonIsNotDirectlyStored(string $value): void
    {
        $this->assertNotSame('', $value);
        $this->assertNull(json_decode($value, true));
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
