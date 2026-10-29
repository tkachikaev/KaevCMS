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

class DiscordSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('discord', 'discord-web-app', 'discord-secret');
        app(SocialAuthSettings::class)->setEnabled('discord', true);
    }

    public function test_discord_start_requests_only_basic_identity_email_scope_pkce_and_state(): void
    {
        $url = (string) $this->get('/auth/social/discord')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        $query = $this->startQuery($url);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('discord-web-app', $query['client_id']);
        $this->assertSame('https://cms.example.test/auth/social/discord/callback', $query['redirect_uri']);
        $this->assertSame('identify email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertArrayNotHasKey('bot', $query);
        $this->assertArrayNotHasKey('guilds', $query);
    }

    public function test_discord_linked_identity_logs_into_player_guard_only(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => self::SUBJECT]);
        $this->callback(email: $user->email)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_discord_matching_email_never_automatically_claims_existing_cms_account(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.test']);
        $this->callback(email: 'victim@example.test', verified: true)->assertRedirect(route('social.discord.register'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $victim->id, 'provider' => 'discord']);
        $this->post('/auth/social/discord/register', ['name' => 'wrongowner'])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_discord_email_verified_flag_is_not_sufficient_to_create_a_cms_account(): void
    {
        $this->callback(email: 'player@example.test', verified: true)->assertRedirect(route('social.discord.register'));
        $this->get('/auth/social/discord/register')->assertOk()->assertSee('player@example.test');
        $this->post('/auth/social/discord/register', ['name' => 'discordplayer'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_email_and_unverified_discord_email_use_local_verification(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.discord.register'));
        $this->get('/auth/social/discord/register')->assertOk();
        $this->assertDatabaseCount('users', 0);

        $this->callback(email: 'player@example.test', verified: false)->assertRedirect(route('social.discord.register'));
        $this->post('/auth/social/discord/register', ['name' => 'discordplayer'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_local_email_registration_creates_separate_social_only_player(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.discord.register'));
        $this->withSession(['social_auth.registration' => [
            'provider' => 'discord', 'subject' => self::SUBJECT, 'email' => 'new@example.test',
            'assured' => true, 'issued' => now()->timestamp, 'email_verification_hash' => null,
        ]])->post('/auth/social/discord/register', ['name' => 'discordplayer'])->assertRedirect(route('account'));
        $user = User::query()->where('name', 'discordplayer')->firstOrFail();
        $this->assertNull($user->password_set_at);
        $this->assertSame('new@example.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => self::SUBJECT,
        ]);
        $this->assertGuest('admin');
    }

    public function test_discord_email_confirmation_requires_a_signed_single_use_same_session_link(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => null, 'from_address' => 'no-reply@example.com',
            'from_name' => 'Test CMS', 'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
        Notification::fake();

        $this->callback(email: null)->assertRedirect(route('social.discord.register'));
        $this->post('/auth/social/discord/email', ['email' => 'player@example.test'])
            ->assertRedirect(route('social.discord.register'));
        $url = null;
        Notification::assertSentOnDemand(SocialRegistrationEmailVerification::class,
            function ($notification, $channels, $notifiable) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return is_string($url) && str_contains($url, '/auth/social/discord/email/verify?');
            });
        $this->assertNotNull($url);
        $this->get($url)->assertRedirect(route('social.discord.register'));
        $this->post('/auth/social/discord/register', ['name' => 'emaildiscord'])
            ->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', [
            'provider' => 'discord', 'provider_user_id' => self::SUBJECT,
        ]);
        $this->post('/auth/social/discord/register', ['name' => 'duplicate'])->assertRedirect();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_discord_rejects_missing_or_incorrect_callback_issuer_before_token_exchange(): void
    {
        $start = $this->start();
        Http::fake();
        $this->get($this->callbackUrl($start, issuer: null))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();

        $start = $this->start();
        Http::fake();
        $this->get($this->callbackUrl($start, issuer: 'https://not-discord.example'))
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
    }

    public function test_bad_state_provider_mixup_and_replayed_callback_cannot_log_in(): void
    {
        $start = $this->start();
        $this->fakeDiscord();
        $callback = $this->callbackUrl($start);
        $this->get('/auth/social/google/callback?'.parse_url($callback, PHP_URL_QUERY))
            ->assertRedirect(route('login'));
        $this->get($callback)->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeDiscord();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.discord.register'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_discord_rejects_denied_scopes_invalid_token_missing_subject_and_api_failures(): void
    {
        $start = $this->start();
        $this->fakeDiscord(scopes: 'identify');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeDiscord(subject: null);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        Http::fake(['https://discord.com/api/v10/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        Http::fake([
            'https://discord.com/api/v10/oauth2/token' => Http::response([
                'access_token' => 'fake-access', 'scope' => 'identify email', 'token_type' => 'Bearer',
            ]),
            'https://discord.com/api/v10/users/@me' => Http::response([], 401),
        ]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_discord_uses_basic_client_auth_and_bearer_profile_header_without_url_tokens(): void
    {
        $start = $this->start();
        $this->fakeDiscord();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.discord.register'));
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/oauth2/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('discord-web-app:discord-secret'))
            && $request['code'] === 'test-code' && $request['code_verifier'] !== ''
            && ! str_contains($request->url(), 'test-code'));
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/v10/users/@me'
            && $request->hasHeader('Authorization', 'Bearer fake-access')
            && ! str_contains($request->url(), 'fake-access'));
    }

    public function test_discord_link_requires_password_and_uses_local_get_hop_for_csp(): void
    {
        $user = User::factory()->create(['password' => Hash::make('MySecret123!')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/discord/link', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->post('/account/security/social/discord/link', ['current_password' => 'MySecret123!'])
            ->assertRedirect(route('security.social.discord.continue'));
        $url = (string) $this->get('/account/security/social/discord/continue')
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://discord.com/oauth2/authorize?', $url);
        $start = $this->startQuery($url);
        $this->fakeDiscord();
        $this->get($this->callbackUrl($start))->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => self::SUBJECT,
        ]);
        $this->delete('/account/security/social/discord', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->delete('/account/security/social/discord', ['current_password' => 'MySecret123!'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'discord',
        ]);
    }

    public function test_discord_cannot_unlink_last_access_method_and_disabled_routes_fail_closed(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'discord', 'provider_user_id' => self::SUBJECT]);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        $this->delete('/account/security/social/discord')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'discord']);
        $this->app['auth']->guard('web')->logout();
        $this->get('/login')->assertOk()->assertSee('Continue with Discord');
        $start = $this->start();
        app(SocialAuthSettings::class)->setEnabled('discord', false);
        $this->get('/login')->assertOk()->assertDontSee('Continue with Discord');
        $this->get('/auth/social/discord')->assertNotFound();
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    public function test_pending_discord_registration_cannot_be_consumed_by_other_provider(): void
    {
        $this->callback(email: null)->assertRedirect(route('social.discord.register'));
        $this->get('/auth/social/yandex/register')->assertRedirect(route('login'));
        $this->post('/auth/social/google/register', ['name' => 'wrong'])->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, string> */
    private function start(): array
    {
        $url = (string) $this->get('/auth/social/discord')->assertRedirect()->headers->get('Location');

        return $this->startQuery($url);
    }

    /** @return array<string, string> */
    private function startQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function callbackUrl(array $start, ?string $issuer = 'https://discord.com'): string
    {
        $params = ['code' => 'test-code', 'state' => $start['state']];
        if ($issuer !== null) {
            $params['iss'] = $issuer;
        }

        return '/auth/social/discord/callback?'.http_build_query($params);
    }

    private function callback(?string $email, bool $verified = false)
    {
        $start = $this->start();
        $this->fakeDiscord(email: $email, verified: $verified);

        return $this->get($this->callbackUrl($start));
    }

    private function fakeDiscord(?string $subject = self::SUBJECT, ?string $email = 'player@example.test', bool $verified = true, string $scopes = 'identify email'): void
    {
        Http::fake([
            'https://discord.com/api/v10/oauth2/token' => Http::response([
                'access_token' => 'fake-access', 'scope' => $scopes,
                'token_type' => 'Bearer', 'expires_in' => 604800,
            ]),
            'https://discord.com/api/v10/users/@me' => Http::response([
                'id' => $subject, 'username' => 'testdiscord', 'global_name' => 'Test Discord',
                'email' => $email, 'verified' => $verified,
            ]),
        ]);
    }
}
