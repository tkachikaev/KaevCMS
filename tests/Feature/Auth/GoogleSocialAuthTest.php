<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('google', 'test-client-id', 'test-client-secret');
        app(SocialAuthSettings::class)->setEnabled('google', true);
        Cache::forget('social.google.oidc.certificates');
    }

    public function test_google_start_uses_state_pkce_nonce_and_fixed_callback(): void
    {
        $url = (string) $this->get('/auth/social/google')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->assertSame('S256', $args['code_challenge_method']);
        $this->assertSame('openid email profile', $args['scope']);
        $this->assertSame('https://cms.example.test/auth/social/google/callback', $args['redirect_uri']);
        $this->assertNotEmpty($args['state']);
        $this->assertNotEmpty($args['nonce']);
    }

    public function test_unknown_google_identity_creates_no_user_until_username_is_chosen(): void
    {
        $this->completeGoogleCallback('subject-one', 'player@gmail.com')
            ->assertRedirect(route('social.google.register'));
        $this->assertDatabaseCount('users', 0);
        $this->get('/auth/social/google/register')->assertOk()->assertSee('player@gmail.com');
        $this->post('/auth/social/google/register', ['name' => 'googleplayer'])
            ->assertRedirect(route('account'));
        $this->assertDatabaseHas('users', ['name' => 'googleplayer', 'email' => 'player@gmail.com']);
        $player = User::query()->where('name', 'googleplayer')->firstOrFail();
        $this->assertNull($player->password_set_at);
        $this->assertNotNull($player->email_verified_at);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $player->id, 'provider' => 'google', 'provider_user_id' => 'subject-one']);
        $this->assertAuthenticatedAs($player, 'web');
        $this->post('/auth/social/google/register', ['name' => 'second'])
            ->assertRedirect();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_preexisting_email_is_not_merged_into_google_identity(): void
    {
        $victim = User::factory()->create(['email' => 'victim@gmail.com']);
        $this->completeGoogleCallback('unlinked-subject', 'victim@gmail.com')->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $victim->id, 'provider' => 'google']);
        $this->assertGuest('web');
    }

    public function test_existing_link_logs_into_web_guard_only(): void
    {
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'existing-subject']);
        $this->completeGoogleCallback('existing-subject', $user->email)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_social_only_user_cannot_disconnect_the_last_available_sign_in_method(): void
    {
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'only-google']);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        $this->delete('/account/security/social/google')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_external_domain_email_requires_additional_confirmation(): void
    {
        $this->completeGoogleCallback('external-user', 'person@example.org')
            ->assertRedirect(route('social.google.register'));
        $this->get('/auth/social/google/register')->assertOk()->assertSee('person@example.org');
        $this->post('/auth/social/google/register', ['name' => 'cannot_skip_email'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_state_is_single_use_and_callback_replay_cannot_register_twice(): void
    {
        $url = (string) $this->get('/auth/social/google')->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->fakeGoogle('subject-replay', 'person@gmail.com', $args['nonce']);
        $callback = '/auth/social/google/callback?'.http_build_query(['code' => 'test-code', 'state' => $args['state']]);
        $this->get($callback)->assertRedirect(route('social.google.register'));
        $this->get($callback)->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_buttons_disappear_and_start_is_blocked_after_admin_disables_provider(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with Google');
        app(SocialAuthSettings::class)->setEnabled('google', false);
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get('/auth/social/google')->assertNotFound();
    }

    public function test_invalid_token_signature_fails_closed_without_creating_user(): void
    {
        $url = (string) $this->get('/auth/social/google')->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->fakeGoogle('subject-tampered', 'person@gmail.com', $args['nonce'], tamper: true);
        $this->get('/auth/social/google/callback?'.http_build_query(['code' => 'test-code', 'state' => $args['state']]))
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_callback_rejected_if_provider_disabled_after_start(): void
    {
        $url = (string) $this->get('/auth/social/google')->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        app(SocialAuthSettings::class)->setEnabled('google', false);
        $this->get('/auth/social/google/callback?'.http_build_query(['code' => 'test-code', 'state' => $args['state']]))
            ->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_link_requires_password_confirmation_and_unlink_preserves_last_method(): void
    {
        $user = User::factory()->create(['password' => Hash::make('CorrectPass-123')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/google/link', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->assertDatabaseCount('user_social_accounts', 0);
        $this->post('/account/security/social/google/link', ['current_password' => 'CorrectPass-123'])
            ->assertRedirect(route('security.social.google.continue'));
        $url = (string) $this->get('/account/security/social/google/continue')
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->fakeGoogle('linked-sub', 'person@gmail.com', $args['nonce']);
        $this->get('/auth/social/google/callback?'.http_build_query(['code' => 'test-code', 'state' => $args['state']]))
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'linked-sub']);
        $this->delete('/account/security/social/google', ['current_password' => 'wrong'])->assertSessionHasErrors('social');
        $this->delete('/account/security/social/google', ['current_password' => 'CorrectPass-123'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseCount('user_social_accounts', 0);
    }

    /** @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function completeGoogleCallback(string $subject, string $email)
    {
        $url = (string) $this->get('/auth/social/google')->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $args);
        $this->fakeGoogle($subject, $email, $args['nonce']);

        return $this->get('/auth/social/google/callback?'.http_build_query(['code' => 'test-code', 'state' => $args['state']]));
    }

    private function fakeGoogle(string $subject, string $email, string $nonce, bool $tamper = false): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'unit-test.example'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        openssl_x509_export($certificate, $pem);
        $header = $this->base64url(json_encode(['alg' => 'RS256', 'kid' => 'test-key']));
        $payload = $this->base64url(json_encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'test-client-id',
            'sub' => $subject, 'nonce' => $nonce, 'email' => $email,
            'email_verified' => true, 'exp' => time() + 3600, 'iat' => time(),
        ]));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        $token = $header.'.'.$payload.'.'.$this->base64url($signature);
        if ($tamper) {
            $token = substr($token, 0, -2).'zz';
        }
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $token], 200),
            'https://www.googleapis.com/oauth2/v1/certs' => Http::response(['test-key' => $pem], 200),
        ]);
    }

    private function base64url(string $plain): string
    {
        return rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');
    }
}
