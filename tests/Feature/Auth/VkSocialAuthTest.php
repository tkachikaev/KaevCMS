<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SocialRegistrationEmailVerification;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VkSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('vk', 'vk-app-100', null);
        app(SocialAuthSettings::class)->setEnabled('vk', true);
    }

    public function test_vk_start_uses_modern_vkid_authorization_code_pkce_state_and_scope(): void
    {
        $start = $this->start();
        $this->assertSame('code', $start['response_type']);
        $this->assertSame('vk-app-100', $start['client_id']);
        $this->assertSame('https://cms.example.test/auth/social/vk/callback', $start['redirect_uri']);
        $this->assertSame('email', $start['scope']);
        $this->assertSame('s256', $start['code_challenge_method']);
        $this->assertNotEmpty($start['code_challenge']);
        $this->assertNotEmpty($start['state']);
        $this->assertArrayNotHasKey('client_secret', $start);
    }

    public function test_vk_linked_identity_can_log_in_without_using_the_admin_guard(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'vk', 'provider_user_id' => '111222333']);
        $this->oauthCallback('111222333', $user->email)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_vk_unlinked_email_never_automatically_links_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'victim@example.test']);
        $this->oauthCallback('111222333', 'victim@example.test')->assertRedirect(route('social.vk.register'));
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'vk']);
        $this->assertDatabaseCount('users', 1);
        $this->post('/auth/social/vk/register', ['name' => 'attacker'])->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_vk_email_from_profile_always_needs_cms_verification(): void
    {
        $this->oauthCallback('111222333', 'player@mail.ru')->assertRedirect(route('social.vk.register'));
        $this->get('/auth/social/vk/register')->assertOk()->assertSee('player@mail.ru');
        $this->post('/auth/social/vk/register', ['name' => 'playername'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vk_profile_without_email_can_request_email_in_cms(): void
    {
        $this->oauthCallback('111222333', null)->assertRedirect(route('social.vk.register'));
        $this->get('/auth/social/vk/register')->assertOk();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vk_verified_registration_creates_social_only_account(): void
    {
        $this->oauthCallback('111222333', 'player@mail.ru')->assertRedirect(route('social.vk.register'));
        $this->withSession(['social_auth.registration' => [
            'provider' => 'vk', 'subject' => '111222333', 'email' => 'player@mail.ru',
            'assured' => true, 'issued' => now()->timestamp, 'email_verification_hash' => null,
        ]])->post('/auth/social/vk/register', ['name' => 'newvkplayer'])->assertRedirect(route('account'));
        $user = User::query()->where('name', 'newvkplayer')->firstOrFail();
        $this->assertNull($user->password_set_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'vk', 'provider_user_id' => '111222333',
        ]);
    }

    public function test_vk_email_verification_uses_signed_one_time_link(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => null, 'from_address' => 'no-reply@example.com',
            'from_name' => 'Test CMS', 'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
        Notification::fake();

        $this->oauthCallback('111222333', 'player@mail.ru')->assertRedirect(route('social.vk.register'));
        $this->post('/auth/social/vk/email', ['email' => 'player@mail.ru'])
            ->assertRedirect(route('social.vk.register'));
        $url = null;
        Notification::assertSentOnDemand(SocialRegistrationEmailVerification::class,
            function ($notification, $channels, $notifiable) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return is_string($url) && str_contains($url, '/auth/social/vk/email/verify?');
            });
        $this->assertNotNull($url);
        $this->get($url)->assertRedirect(route('social.vk.register'));
        $this->post('/auth/social/vk/register', ['name' => 'verifiedvk'])->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'vk', 'provider_user_id' => '111222333']);
        $this->post('/auth/social/vk/register', ['name' => 'secondvk'])->assertRedirect();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_vk_token_exchange_sends_state_device_id_and_pkce_without_secret(): void
    {
        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', $start['state']);
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.vk.register'));
        Http::assertSent(fn ($request) => $request->url() === 'https://id.vk.ru/oauth2/auth'
            && $request['device_id'] === 'device-123'
            && $request['state'] === $start['state']
            && $request['client_id'] === 'vk-app-100'
            && $request['redirect_uri'] === 'https://cms.example.test/auth/social/vk/callback'
            && is_string($request['code_verifier'])
            && ! isset($request['client_secret']) && ! isset($request['service_token']));
        Http::assertSent(fn ($request) => $request->url() === 'https://id.vk.ru/oauth2/user_info'
            && $request['client_id'] === 'vk-app-100'
            && $request['access_token'] === 'test-vk-access'
            && ! str_contains($request->url(), 'test-vk-access'));
    }

    public function test_vk_callback_accepts_official_sdk_json_payload(): void
    {
        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', $start['state']);
        $payload = json_encode(['state' => $start['state'], 'code' => 'test-code', 'device_id' => 'device-123']);
        $this->get('/auth/social/vk/callback?'.http_build_query(['payload' => $payload]))
            ->assertRedirect(route('social.vk.register'));
    }

    public function test_vk_rejects_missing_or_malformed_device_id_without_token_exchange(): void
    {
        $start = $this->start();
        Http::fake();
        $this->get('/auth/social/vk/callback?'.http_build_query(['state' => $start['state'], 'code' => 'test-code']))
            ->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertGuest('web');

        $start = $this->start();
        $this->get('/auth/social/vk/callback?'.http_build_query([
            'state' => $start['state'], 'code' => 'test-code', 'device_id' => '../bad',
        ]))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_vk_rejects_mismatched_response_state_or_subject(): void
    {
        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', 'unrelated-state');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', $start['state'], tokenSubject: '999');
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vk_rejects_untrusted_profile_subject_and_token_errors(): void
    {
        $start = $this->start();
        $this->fakeVk('abc-123', 'player@mail.ru', $start['state']);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertGuest('web');
        $start = $this->start();
        Http::fake(['https://id.vk.ru/oauth2/auth' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vk_callback_is_single_use_and_cannot_finish_other_provider_flow(): void
    {
        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', $start['state']);
        $this->get('/auth/social/yandex/callback?'.http_build_query([
            'state' => $start['state'], 'code' => 'test-code', 'device_id' => 'device-123',
        ]))->assertRedirect(route('login'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);

        $start = $this->start();
        $this->fakeVk('111222333', 'player@mail.ru', $start['state']);
        $callback = $this->callbackUrl($start);
        $this->get($callback)->assertRedirect(route('social.vk.register'));
        $this->get($callback)->assertRedirect(route('login'));
    }

    public function test_vk_rejects_ambiguous_payload_and_flat_parameters(): void
    {
        $start = $this->start();
        Http::fake();
        $payload = json_encode(['state' => $start['state'], 'code' => 'test-code', 'device_id' => 'device-123']);
        $this->get('/auth/social/vk/callback?'.http_build_query(['payload' => $payload, 'state' => $start['state']]))
            ->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_vk_link_and_unlink_require_password_step_up(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Secret123!')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/vk/link', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->post('/account/security/social/vk/link', ['current_password' => 'Secret123!'])
            ->assertOk()->assertViewIs('auth.social-continue')->assertViewHas('continueUrl');
        $url = (string) $this->get('/account/security/social/vk/continue')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://id.vk.ru/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->fakeVk('111222333', 'player@mail.ru', $query['state']);
        $this->get('/auth/social/vk/callback?'.http_build_query([
            'code' => 'test-code', 'state' => $query['state'], 'device_id' => 'device-123',
        ]))->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'vk']);
        $this->delete('/account/security/social/vk', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->delete('/account/security/social/vk', ['current_password' => 'Secret123!'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'vk']);
    }

    public function test_vk_social_only_account_cannot_unlink_last_sign_in(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'vk', 'provider_user_id' => '111222333']);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        $this->delete('/account/security/social/vk')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'vk']);
    }

    public function test_vk_provider_toggle_hides_button_and_blocks_callbacks(): void
    {
        $this->get('/login')->assertOk()->assertSee(__('Continue with :provider', ['provider' => 'VK ID']));
        $start = $this->start();
        app(SocialAuthSettings::class)->setEnabled('vk', false);
        $this->get('/login')->assertOk()->assertDontSee(__('Continue with :provider', ['provider' => 'VK ID']));
        $this->get('/auth/social/vk')->assertNotFound();
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    /** @return array<string, string> */
    private function start(): array
    {
        $url = (string) $this->get('/auth/social/vk')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://id.vk.ru/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    private function callbackUrl(array $start): string
    {
        return '/auth/social/vk/callback?'.http_build_query([
            'code' => 'test-code', 'state' => $start['state'], 'device_id' => 'device-123',
        ]);
    }

    private function oauthCallback(string $id, ?string $email)
    {
        $start = $this->start();
        $this->fakeVk($id, $email, $start['state']);

        return $this->get($this->callbackUrl($start));
    }

    private function fakeVk(string $id, ?string $email, string $state, ?string $tokenSubject = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://id.vk.ru/oauth2/auth' => Http::response([
                'access_token' => 'test-vk-access', 'state' => $state,
                'user_id' => $tokenSubject ?? $id,
            ], 200),
            'https://id.vk.ru/oauth2/user_info' => Http::response([
                'user' => [
                    'user_id' => $id, 'email' => $email, 'first_name' => 'Test', 'last_name' => 'Player',
                ],
            ], 200),
        ]);
    }
}
