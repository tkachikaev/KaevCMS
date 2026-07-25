<?php

namespace Tests\Feature\Auth;

use App\Auth\Passwords\UtcPasswordBrokerManager;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicUserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_disabled_by_default(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Регистрация отключена');

        $this->post('/register', [
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertForbidden();
    }

    public function test_password_validation_messages_are_in_russian(): void
    {
        app(RegistrationSettings::class)->update(true, false);

        $this->post('/register', [
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertSessionHasErrors([
            'password' => 'Пароль должен содержать хотя бы одну букву.',
        ]);

        $this->post('/register', [
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ])->assertSessionHasErrors([
            'password' => 'Пароль должен содержать хотя бы одну цифру.',
        ]);
    }

    public function test_user_can_register_without_email_verification(): void
    {
        app(RegistrationSettings::class)->update(true, false);

        $this->post('/register', [
            'name' => 'Player_One',
            'email' => 'PLAYER@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(route('account'));

        $user = User::query()->firstOrFail();
        $this->assertSame('player_one', $user->name);
        $this->assertSame('player@example.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull($user->last_login_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_receives_verification_notification_when_required(): void
    {
        Notification::fake();
        $this->configureReadyMail();
        app(RegistrationSettings::class)->update(true, true);

        $this->post('/register', [
            'name' => 'player_two',
            'email' => 'player2@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class);

        $this->actingAs($user)->get('/account')->assertRedirect(route('verification.notice'));
    }

    public function test_user_can_verify_email_with_signed_link(): void
    {
        app(RegistrationSettings::class)->update(true, true);
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('account'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_user_can_login_with_login_or_email_and_logout(): void
    {
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);

        $this->post('/login', [
            'login' => 'PLAYER',
            'password' => 'Password123',
        ])->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post('/login', [
            'login' => 'PLAYER@example.com',
            'password' => 'Password123',
        ])->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_public_login_is_limited_by_ip(): void
    {
        config()->set('cms.public_auth.login_ip_per_minute', 2);
        config()->set('cms.public_auth.login_identity_per_hour', 20);

        foreach (range(1, 2) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
                ->post('/login', [
                    'login' => 'PLAYER@example.com',
                    'password' => 'WrongPassword123',
                ])
                ->assertSessionHasErrors('login');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->post('/login', [
                'login' => 'player@example.com',
                'password' => 'WrongPassword123',
            ])
            ->assertStatus(429);
    }

    public function test_public_login_is_limited_by_normalized_identity_across_ip_addresses(): void
    {
        config()->set('cms.public_auth.login_ip_per_minute', 20);
        config()->set('cms.public_auth.login_identity_per_hour', 2);

        foreach (['203.0.113.42', '203.0.113.43'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', [
                    'login' => 'PLAYER@example.com',
                    'password' => 'WrongPassword123',
                ])
                ->assertSessionHasErrors('login');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->post('/login', [
                'login' => 'player@example.com',
                'password' => 'WrongPassword123',
            ])
            ->assertStatus(429);
    }

    public function test_registration_is_limited_by_normalized_email_across_ip_addresses(): void
    {
        app(RegistrationSettings::class)->update(true, false);
        config()->set('cms.public_auth.registration_ip_per_minute', 20);
        config()->set('cms.public_auth.registration_identity_per_hour', 2);

        foreach (['203.0.113.45', '203.0.113.46'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/register', [
                    'name' => 'player',
                    'email' => 'PLAYER@example.com',
                    'password' => 'weak',
                    'password_confirmation' => 'different',
                ])
                ->assertSessionHasErrors('password');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.47'])
            ->post('/register', [
                'name' => 'player',
                'email' => 'player@example.com',
                'password' => 'weak',
                'password_confirmation' => 'different',
            ])
            ->assertStatus(429);
    }

    public function test_password_reset_requests_are_limited_per_email_across_ip_addresses(): void
    {
        Notification::fake();
        $this->configureReadyMail();
        config()->set('cms.public_auth.password_email_ip_per_minute', 20);
        config()->set('cms.public_auth.password_email_identity_per_hour', 2);

        foreach (['203.0.113.51', '203.0.113.52'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/forgot-password', ['email' => 'UNKNOWN@example.com'])
                ->assertSessionHas('status');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.53'])
            ->post('/forgot-password', ['email' => 'unknown@example.com'])
            ->assertStatus(429);
    }

    public function test_password_reset_submission_is_limited_per_email_even_when_tokens_change(): void
    {
        config()->set('cms.public_auth.password_reset_ip_per_minute', 20);
        config()->set('cms.public_auth.password_reset_identity_per_hour', 2);

        foreach ([
            ['203.0.113.61', 'invalid-token-one'],
            ['203.0.113.62', 'invalid-token-two'],
        ] as [$ip, $token]) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/reset-password', [
                    'token' => $token,
                    'email' => 'PLAYER@example.com',
                    'password' => 'NewPassword123',
                    'password_confirmation' => 'NewPassword123',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.63'])
            ->post('/reset-password', [
                'token' => 'invalid-token-three',
                'email' => 'player@example.com',
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])
            ->assertStatus(429);
    }

    public function test_reset_form_rejects_invalid_token_before_password_is_entered(): void
    {
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);

        $this->get('/reset-password/invalid-token?email='.$user->email)
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }

    public function test_localized_reset_form_uses_the_reset_token_instead_of_the_locale_route_parameter(): void
    {
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);
        $token = Password::broker('users')->createToken($user);

        $this->get('/ru/reset-password/'.$token.'?email='.$user->email)
            ->assertOk()
            ->assertSee('name="token" type="hidden" value="'.$token.'"', false);

        $this->post('/ru/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect('/ru/login');

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_reset_form_locks_email_to_the_address_bound_to_the_token(): void
    {
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);
        $token = Password::broker('users')->createToken($user);

        $this->get('/reset-password/'.$token.'?email='.$user->email)
            ->assertOk()
            ->assertSee('name="email" type="hidden" value="player@example.com"', false)
            ->assertSee('id="reset_email"', false)
            ->assertSee('readonly', false);
    }

    public function test_password_reset_token_remains_valid_when_web_and_worker_timezones_differ(): void
    {
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);
        $originalPhpTimezone = date_default_timezone_get();
        $originalAppTimezone = (string) config('app.timezone');

        try {
            date_default_timezone_set('Pacific/Honolulu');
            config()->set('app.timezone', 'Pacific/Honolulu');
            $token = Password::broker('users')->createToken($user);

            date_default_timezone_set('Pacific/Kiritimati');
            config()->set('app.timezone', 'Pacific/Kiritimati');

            $this->assertInstanceOf(UtcPasswordBrokerManager::class, app('auth.password'));
            $this->assertTrue(Password::broker('users')->tokenExists($user, $token));

            $this->get('/reset-password/'.$token.'?email='.$user->email)
                ->assertOk();

            $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'NewPassword123',
                'password_confirmation' => 'NewPassword123',
            ])->assertRedirect(route('login'));

            $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        } finally {
            date_default_timezone_set($originalPhpTimezone);
            config()->set('app.timezone', $originalAppTimezone);
        }
    }

    public function test_password_reset_notification_can_be_requested_without_revealing_unknown_email(): void
    {
        Notification::fake();
        $this->configureReadyMail();
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        $this->post('/forgot-password', ['email' => 'unknown@example.com'])
            ->assertSessionHas('status');
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
}
