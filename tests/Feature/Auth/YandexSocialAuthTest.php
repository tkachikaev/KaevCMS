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

class YandexSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('yandex', 'yandex-client', 'yandex-secret');
        app(SocialAuthSettings::class)->setEnabled('yandex', true);
    }

    public function test_yandex_start_uses_exact_endpoint_pkce_state_and_scope(): void
    {
        $url = (string) $this->get('/auth/social/yandex')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://oauth.yandex.ru/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('yandex-client', $query['client_id']);
        $this->assertSame('https://cms.example.test/auth/social/yandex/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('login:email login:info', $query['scope']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['code_challenge']);
    }

    public function test_yandex_login_uses_saved_subject_and_never_admin_guard(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '1000034426']);
        $this->oauthCallback('1000034426', $user->email)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_unlinked_yandex_identity_never_automatically_joins_existing_email(): void
    {
        $user = User::factory()->create(['email' => 'victim@yandex.ru']);
        $this->oauthCallback('1000034426', 'victim@yandex.ru')->assertRedirect(route('social.yandex.register'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'yandex']);
        $this->post('/auth/social/yandex/register', ['name' => 'attacker'])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_even_yandex_domain_email_requires_cms_verification_before_registration(): void
    {
        $this->oauthCallback('1000034426', 'player@yandex.ru')->assertRedirect(route('social.yandex.register'));
        $this->get('/auth/social/yandex/register')->assertOk()->assertSee('player@yandex.ru');
        $this->post('/auth/social/yandex/register', ['name' => 'yandexplayer'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_registration_uses_only_yandex_id_and_creates_a_social_only_account(): void
    {
        $this->oauthCallback('1000034426', 'player@yandex.ru')->assertRedirect(route('social.yandex.register'));
        $this->withSession(['social_auth.registration' => [
            'provider' => 'yandex', 'subject' => '1000034426', 'email' => 'player@yandex.ru',
            'assured' => true, 'issued' => now()->timestamp, 'email_verification_hash' => null,
        ]])->post('/auth/social/yandex/register', ['name' => 'yandexplayer'])->assertRedirect(route('account'));
        $user = User::query()->where('name', 'yandexplayer')->firstOrFail();
        $this->assertNull($user->password_set_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'yandex', 'provider_user_id' => '1000034426',
        ]);
    }

    public function test_yandex_email_verification_uses_a_signed_one_time_link_in_same_session(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => null, 'from_address' => 'no-reply@example.com',
            'from_name' => 'Test CMS', 'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
        Notification::fake();

        $this->oauthCallback('1000034426', 'player@yandex.ru')->assertRedirect(route('social.yandex.register'));
        $this->post('/auth/social/yandex/email', ['email' => 'player@yandex.ru'])
            ->assertRedirect(route('social.yandex.register'));
        $url = null;
        Notification::assertSentOnDemand(SocialRegistrationEmailVerification::class,
            function ($notification, $channels, $notifiable) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return is_string($url) && str_contains($url, '/auth/social/yandex/email/verify?');
            });
        $this->assertNotNull($url);
        $this->get($url)->assertRedirect(route('social.yandex.register'));
        $this->post('/auth/social/yandex/register', ['name' => 'verifiedyandex'])
            ->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'yandex', 'provider_user_id' => '1000034426']);
        // One-time registration; confirmation is not a second ticket.
        $this->post('/auth/social/yandex/register', ['name' => 'seconduser'])->assertRedirect();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_pending_yandex_registration_cannot_be_used_for_google_registration(): void
    {
        $this->oauthCallback('1000034426', 'player@yandex.ru')->assertRedirect(route('social.yandex.register'));
        $this->get('/auth/social/google/register')->assertRedirect(route('login'));
        $this->post('/auth/social/google/email', ['email' => 'player@yandex.ru'])->assertNotFound();
        $this->get('/auth/social/yandex/register')->assertOk();
    }

    public function test_token_or_profile_failure_never_creates_or_authenticates_a_user(): void
    {
        $start = $this->start();
        Http::fake(['https://oauth.yandex.ru/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_mismatched_client_id_and_missing_subject_fail_closed(): void
    {
        $start = $this->start();
        $this->fakeYandex('1000034426', 'player@yandex.ru', clientId: 'other-app');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeYandex(null, 'player@yandex.ru');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_yandex_access_token_is_sent_in_authorization_header_not_query(): void
    {
        $start = $this->start();
        $this->fakeYandex('1000034426', 'player@yandex.ru');
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.yandex.register'));
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://login.yandex.ru/info?')
            && str_contains($request->url(), 'format=json')
            && $request->hasHeader('Authorization', 'OAuth test-access-token')
            && ! str_contains($request->url(), 'test-access-token'));
    }

    public function test_replayed_callback_and_cross_provider_callback_are_rejected(): void
    {
        $start = $this->start();
        $this->fakeYandex('1000034426', 'player@yandex.ru');
        $callback = $this->callbackUrl($start);
        $this->get('/auth/social/google/callback?'.parse_url($callback, PHP_URL_QUERY))
            ->assertRedirect(route('login'));
        $this->get($callback)->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeYandex('1000034426', 'player@yandex.ru');
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.yandex.register'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    public function test_yandex_links_and_unlinks_only_after_current_password_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('MySecret123!')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/yandex/link', ['current_password' => 'invalid'])
            ->assertSessionHasErrors('social');
        $this->post('/account/security/social/yandex/link', ['current_password' => 'MySecret123!'])
            ->assertOk()->assertViewIs('auth.social-continue')->assertViewHas('continueUrl');
        $url = (string) $this->get('/account/security/social/yandex/continue')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://oauth.yandex.ru/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->fakeYandex('1000034426', 'player@yandex.ru');
        $this->get('/auth/social/yandex/callback?'.http_build_query(['code' => 'test-code', 'state' => $query['state']]))
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'yandex']);
        $this->delete('/account/security/social/yandex', ['current_password' => 'invalid'])->assertSessionHasErrors('social');
        $this->delete('/account/security/social/yandex', ['current_password' => 'MySecret123!'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'yandex']);
    }

    public function test_social_only_account_cannot_unlink_its_last_provider_even_if_disabled(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'yandex', 'provider_user_id' => '1000034426']);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        app(SocialAuthSettings::class)->setEnabled('yandex', false);
        $this->delete('/account/security/social/yandex')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'yandex']);
    }

    public function test_enabled_buttons_are_independent_and_disabled_callback_fails_closed(): void
    {
        $this->get('/login')->assertOk()->assertSee(__('Continue with :provider', ['provider' => 'Яндекс ID']));
        $start = $this->start();
        app(SocialAuthSettings::class)->setEnabled('yandex', false);
        $this->get('/login')->assertOk()->assertDontSee(__('Continue with :provider', ['provider' => 'Яндекс ID']));
        $this->get('/auth/social/yandex')->assertNotFound();
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    /** @return array<string, string> */
    private function start(): array
    {
        $url = (string) $this->get('/auth/social/yandex')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);

        return $args;
    }

    private function callbackUrl(array $start): string
    {
        return '/auth/social/yandex/callback?'.http_build_query(['code' => 'test-code', 'state' => $start['state']]);
    }

    private function oauthCallback(string $subject, string $email)
    {
        $start = $this->start();
        $this->fakeYandex($subject, $email);

        return $this->get($this->callbackUrl($start));
    }

    private function fakeYandex(?string $subject, ?string $email, string $clientId = 'yandex-client'): void
    {
        Http::fake([
            'https://oauth.yandex.ru/token' => Http::response(['access_token' => 'test-access-token', 'token_type' => 'bearer'], 200),
            'https://login.yandex.ru/info*' => Http::response([
                'id' => $subject, 'client_id' => $clientId, 'default_email' => $email,
                'display_name' => 'Test player', 'psuid' => 'client-specific-identifier',
            ], 200),
        ]);
    }
}
