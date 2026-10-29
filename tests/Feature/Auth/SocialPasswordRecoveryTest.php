<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\User;
use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialIdentityService;
use App\Services\Auth\Social\SocialPasswordRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SocialPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_revokes_social_links_and_rotates_web_session_version(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);
        $service = app(SocialIdentityService::class);
        $service->link($user, new SocialIdentity('google', 'google-account'), (int) $user->auth_session_version);
        $service->link($user, new SocialIdentity('discord', 'discord-account'), (int) $user->auth_session_version);
        $before = (int) $user->auth_session_version;
        $rememberToken = $user->remember_token;
        $resetToken = Password::broker('users')->createToken($user);

        $this->post('/reset-password', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect(route('login'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewPassword123', $fresh->password));
        $this->assertNotNull($fresh->password_set_at);
        $this->assertSame($before + 1, (int) $fresh->auth_session_version);
        $this->assertNotSame($rememberToken, $fresh->remember_token);
        $this->assertSame(0, $fresh->socialAccounts()->count());
        $this->assertFalse(Password::broker('users')->tokenExists($fresh, $resetToken));
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.password_reset_links_revoked', 'result' => 'success']);
    }

    public function test_second_recovery_with_same_token_is_rejected_without_changes(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $recovery = app(SocialPasswordRecovery::class);
        $recovery->reset($user, 'FirstPassword123', $token);
        $version = $user->fresh()->auth_session_version;

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'SecondPassword123',
            'password_confirmation' => 'SecondPassword123',
        ])->assertSessionHasErrors('email');
        $this->assertSame($version, $user->fresh()->auth_session_version);
        $this->assertFalse(Hash::check('SecondPassword123', $user->fresh()->password));
    }

    public function test_old_player_session_is_revoked_but_admin_login_is_preserved(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $admin = Admin::factory()->create();
        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
        $token = Password::broker('users')->createToken($user);
        app(SocialPasswordRecovery::class)->reset($user, 'NewPassword123', $token);
        $user->refresh(); // PHPUnit retains the in-memory guard user between requests.

        $this->get('/account')->assertRedirect();
        $this->assertGuest('web');
        $this->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_legacy_web_session_without_auth_version_is_rejected(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)->withSession(['auth_session_version_web' => null])
            ->get('/account')->assertRedirect();
        $this->assertGuest('web');
    }

    public function test_regular_password_change_preserves_social_links(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123'), 'email_verified_at' => now()]);
        app(SocialIdentityService::class)->link($user, new SocialIdentity('google', 'kept-account'), (int) $user->auth_session_version);

        $this->actingAs($user)->put('/account/security/password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
        $this->assertNotNull($user->fresh()->password_set_at);
    }
}
