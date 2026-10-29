<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SocialRegistrationEmailVerification;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FacebookSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = '100001234567891';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('facebook', 'facebook-app-id', 'facebook-app-secret');
        app(SocialAuthSettings::class)->setEnabled('facebook', true);
    }

    public function test_start_uses_only_email_permission_with_state_and_registered_redirect(): void
    {
        $url = (string) $this->get('/auth/social/facebook')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
        $query = $this->startQuery($url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('facebook-app-id', $query['client_id']);
        $this->assertSame('https://cms.example.test/auth/social/facebook/callback', $query['redirect_uri']);
        $this->assertSame('email', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertArrayNotHasKey('access_token', $query);
        $this->assertArrayNotHasKey('code_challenge', $query); // Meta hosted web code flow, not PKCE.
    }

    public function test_facebook_identity_logs_into_player_guard_only(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'facebook', 'provider_user_id' => self::SUBJECT]);
        $this->callback(email: $user->email)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_matching_facebook_email_never_claims_existing_cms_account(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $this->callback(email: 'victim@example.test')->assertRedirect(route('social.facebook.register'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $victim->id, 'provider' => 'facebook']);
        $this->post('/auth/social/facebook/register', ['name' => 'notvictim'])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_facebook_email_is_not_assured_and_requires_cms_verification(): void
    {
        $this->callback(email: 'new@example.test')->assertRedirect(route('social.facebook.register'));
        $this->get('/auth/social/facebook/register')->assertOk()->assertSee('new@example.test');
        $this->post('/auth/social/facebook/register', ['name' => 'facebookplayer'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_email_can_be_supplied_in_cms_registration_form(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.facebook.register'));
        $this->get('/auth/social/facebook/register')->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_cms_email_creates_new_social_only_player(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.facebook.register'));
        $this->withSession(['social_auth.registration' => [
            'provider' => 'facebook', 'subject' => self::SUBJECT, 'email' => 'new@example.test',
            'assured' => true, 'issued' => now()->timestamp, 'email_verification_hash' => null,
        ]])->post('/auth/social/facebook/register', ['name' => 'facebookplayer'])->assertRedirect(route('account'));
        $user = User::query()->where('name', 'facebookplayer')->firstOrFail();
        $this->assertNull($user->password_set_at);
        $this->assertSame('new@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => self::SUBJECT,
        ]);
        $this->assertGuest('admin');
    }

    public function test_real_email_verification_is_signed_and_session_bound(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => null, 'from_address' => 'no-reply@example.com',
            'from_name' => 'Test CMS', 'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
        Notification::fake();
        $this->callback(email: null)->assertRedirect(route('social.facebook.register'));
        $this->post('/auth/social/facebook/email', ['email' => 'new@example.test'])
            ->assertRedirect(route('social.facebook.register'));
        $url = null;
        Notification::assertSentOnDemand(SocialRegistrationEmailVerification::class,
            function ($notification, $channels, $notifiable) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return is_string($url) && str_contains($url, '/auth/social/facebook/email/verify?');
            });
        $this->assertNotNull($url);
        $this->get($url)->assertRedirect(route('social.facebook.register'));
        $this->post('/auth/social/facebook/register', ['name' => 'facebookverified'])->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'facebook', 'provider_user_id' => self::SUBJECT]);
        $this->post('/auth/social/facebook/register', ['name' => 'duplicate'])->assertRedirect();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_wrong_provider_state_and_replayed_callback_are_rejected(): void
    {
        $start = $this->start();
        $this->fakeFacebook();
        $callback = $this->callbackUrl($start);
        $this->get('/auth/social/discord/callback?'.parse_url($callback, PHP_URL_QUERY))
            ->assertRedirect(route('login'));
        $this->get($callback)->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeFacebook();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.facebook.register'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_denied_auth_bogus_state_and_failed_token_exchange_reject_access(): void
    {
        $start = $this->start();
        Http::fake();
        $this->get('/auth/social/facebook/callback?'.http_build_query(['error' => 'access_denied', 'state' => $start['state']]))
            ->assertRedirect(route('login'));
        Http::assertNothingSent();

        $start = $this->start();
        Http::fake();
        $this->get('/auth/social/facebook/callback?code=test-code&state=invalid')->assertRedirect(route('login'));
        Http::assertNothingSent();

        $start = $this->start();
        Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token' => Http::response(['error' => ['message' => 'bad code']], 400)]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_token_wrong_token_type_and_profile_failure_are_rejected(): void
    {
        $start = $this->start();
        Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token' => Http::response(['access_token' => ''], 200)]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));

        $start = $this->start();
        $this->fakeFacebook(tokenType: 'Basic');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));

        $start = $this->start();
        Http::fake([
            'https://graph.facebook.com/v25.0/oauth/access_token' => Http::response(['access_token' => 'fake-access'], 200),
            'https://graph.facebook.com/v25.0/me*' => Http::response([], 500),
        ]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_subject_and_provider_error_payload_are_rejected(): void
    {
        $start = $this->start();
        $this->fakeFacebook(subject: 'not-a-facebook-id');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));

        $start = $this->start();
        $this->fakeFacebook(subject: null);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));

        $start = $this->start();
        $this->fakeFacebook(profileError: true);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_secret_and_tokens_are_not_added_to_login_url_or_profile_url(): void
    {
        $start = $this->start();
        $this->fakeFacebook();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.facebook.register'));
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/oauth/access_token'
            && $request['code'] === 'test-code'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_id'] === 'facebook-app-id'
            && $request['client_secret'] === 'facebook-app-secret'
            && $request['redirect_uri'] === 'https://cms.example.test/auth/social/facebook/callback');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/me?')
            && $request->hasHeader('Authorization', 'Bearer fake-access')
            && ! str_contains($request->url(), 'fake-access')
            && ! str_contains($request->url(), 'facebook-app-secret')
            && str_contains($request->url(), 'appsecret_proof='.hash_hmac('sha256', 'fake-access', 'facebook-app-secret')));
    }

    public function test_link_requires_step_up_and_uses_get_hop_for_csp(): void
    {
        $user = User::factory()->create(['password' => Hash::make('MySecret123!')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/facebook/link', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->post('/account/security/social/facebook/link', ['current_password' => 'MySecret123!'])
            ->assertRedirect(route('security.social.facebook.continue'));
        $url = (string) $this->get('/account/security/social/facebook/continue')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $url);
        $this->fakeFacebook();
        $this->get($this->callbackUrl($this->startQuery($url)))->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => self::SUBJECT,
        ]);
        $this->delete('/account/security/social/facebook', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->delete('/account/security/social/facebook', ['current_password' => 'MySecret123!'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'facebook']);
    }

    public function test_last_login_method_is_protected_and_disabled_provider_fails_closed(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'facebook', 'provider_user_id' => self::SUBJECT]);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        $this->delete('/account/security/social/facebook')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'facebook']);
        $this->app['auth']->guard('web')->logout();
        $this->get('/login')->assertOk()->assertSee('Continue with Facebook');
        $start = $this->start();
        app(SocialAuthSettings::class)->setEnabled('facebook', false);
        $this->get('/login')->assertOk()->assertDontSee('Continue with Facebook');
        $this->get('/auth/social/facebook')->assertNotFound();
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    public function test_pending_facebook_registration_cannot_be_used_for_google_or_discord(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.facebook.register'));
        $this->get('/auth/social/google/register')->assertRedirect(route('login'));
        $this->post('/auth/social/discord/register', ['name' => 'other'])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, string> */
    private function start(): array
    {
        $url = (string) $this->get('/auth/social/facebook')->assertRedirect()->headers->get('Location');

        return $this->startQuery($url);
    }

    /** @return array<string, string> */
    private function startQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function callbackUrl(array $start): string
    {
        return '/auth/social/facebook/callback?'.http_build_query(['code' => 'test-code', 'state' => $start['state']]);
    }

    private function callback(?string $email)
    {
        $start = $this->start();
        $this->fakeFacebook(email: $email);

        return $this->get($this->callbackUrl($start));
    }

    private function fakeFacebook(?string $subject = self::SUBJECT, ?string $email = 'player@example.test', ?string $tokenType = 'Bearer', bool $profileError = false): void
    {
        Http::fake([
            'https://graph.facebook.com/v25.0/oauth/access_token' => Http::response([
                'access_token' => 'fake-access', 'token_type' => $tokenType, 'expires_in' => 3600,
            ]),
            'https://graph.facebook.com/v25.0/me*' => Http::response($profileError
                ? ['error' => ['message' => 'invalid token']]
                : ['id' => $subject, 'name' => 'Test Facebook', 'email' => $email]),
        ]);
    }
}
