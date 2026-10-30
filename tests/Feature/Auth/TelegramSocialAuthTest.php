<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SocialRegistrationEmailVerification;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\TelegramIdTokenVerifier;
use App\Services\MailSettings;
use App\Services\RegistrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TelegramSocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://cms.example.test');
        app(RegistrationSettings::class)->update(true, false);
        app(SocialAuthSettings::class)->updateCredentials('telegram', '123456789', 'tg-client-secret');
    }

    public function test_without_approved_jwt_library_telegram_cannot_be_enabled_or_displayed(): void
    {
        $this->simulateUnavailableLibrary();
        $settings = app(SocialAuthSettings::class);
        $this->assertSame('pending', $settings->values('telegram')['status']);
        $this->assertFalse($settings->isAvailable('telegram'));
        $this->expectException(\DomainException::class);
        $settings->setEnabled('telegram', true);
    }

    public function test_unavailable_telegram_never_shows_button_or_accepts_callback(): void
    {
        $this->simulateUnavailableLibrary();
        $this->get('/login')->assertOk()->assertDontSee(__('Continue with :provider', ['provider' => 'Telegram']));
        $this->get('/auth/social/telegram')->assertNotFound();
        $this->get('/auth/social/telegram/callback?state=fake&code=fake')->assertRedirect(route('login'));
    }

    public function test_telegram_starts_authorization_code_pkce_nonce_using_registered_callback(): void
    {
        $this->enableFakeVerifier();
        $start = $this->start();
        $this->assertSame('123456789', $start['client_id']);
        $this->assertSame('code', $start['response_type']);
        $this->assertSame('openid profile', $start['scope']);
        $this->assertSame('https://cms.example.test/auth/social/telegram/callback', $start['redirect_uri']);
        $this->assertSame('S256', $start['code_challenge_method']);
        $this->assertNotEmpty($start['code_challenge']);
        $this->assertNotEmpty($start['nonce']);
        $this->assertNotEmpty($start['state']);
        $this->assertArrayNotHasKey('phone', $start);
    }

    public function test_telegram_callback_trusts_only_verified_subject_and_never_infers_email(): void
    {
        $this->enableFakeVerifier();
        $start = $this->start();
        $this->fakeTelegram();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.telegram.register'));
        $this->get('/auth/social/telegram/register')->assertOk();
        $this->post('/auth/social/telegram/register', ['name' => 'tgplayer'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth.telegram.org/token'
            && $request->hasHeader('Authorization') && $request['code_verifier'] !== ''
            && $request['client_id'] === '123456789'
            && ! str_contains($request->url(), 'test-code')
            && ! str_contains($request->url(), 'tg-client-secret'));
    }

    public function test_telegram_linked_identity_logs_into_website_not_admin(): void
    {
        $this->enableFakeVerifier();
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'telegram', 'provider_user_id' => '987654321']);
        $start = $this->start();
        $this->fakeTelegram();
        $this->get($this->callbackUrl($start))->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_telegram_verified_email_can_create_one_cms_account(): void
    {
        $this->enableFakeVerifier();
        $start = $this->start();
        $this->fakeTelegram();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.telegram.register'));
        $this->withSession(['social_auth.registration' => [
            'provider' => 'telegram', 'subject' => '987654321', 'email' => 'player@example.test',
            'assured' => true, 'issued' => now()->timestamp, 'email_verification_hash' => null,
        ]])->post('/auth/social/telegram/register', ['name' => 'tgplayer'])->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', ['provider' => 'telegram', 'provider_user_id' => '987654321']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_telegram_new_registration_requires_a_signed_email_confirmation(): void
    {
        $this->enableFakeVerifier();
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => null, 'from_address' => 'no-reply@example.com',
            'from_name' => 'Test CMS', 'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
        Notification::fake();

        $start = $this->start();
        $this->fakeTelegram();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.telegram.register'));
        $this->post('/auth/social/telegram/email', ['email' => 'player@example.test'])
            ->assertRedirect(route('social.telegram.register'));
        $url = null;
        Notification::assertSentOnDemand(SocialRegistrationEmailVerification::class,
            function ($notification, $channels, $notifiable) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return is_string($url) && str_contains($url, '/auth/social/telegram/email/verify?');
            });
        $this->assertNotNull($url);
        $this->get($url)->assertRedirect(route('social.telegram.register'));
        $this->post('/auth/social/telegram/register', ['name' => 'verifiedtelegram'])
            ->assertRedirect(route('account'));
        $this->assertDatabaseHas('user_social_accounts', [
            'provider' => 'telegram', 'provider_user_id' => '987654321',
        ]);
    }

    public function test_telegram_social_only_account_cannot_disconnect_its_last_sign_in(): void
    {
        $this->enableFakeVerifier();
        $user = User::factory()->create(['password_set_at' => null]);
        $user->socialAccounts()->create(['provider' => 'telegram', 'provider_user_id' => '987654321']);
        $this->actingAs($user, 'web')->withSession(['social_authenticated_at' => now()->timestamp]);
        $this->delete('/account/security/social/telegram')->assertSessionHasErrors('social');
        $this->assertDatabaseHas('user_social_accounts', [
            'user_id' => $user->id, 'provider' => 'telegram', 'provider_user_id' => '987654321',
        ]);
    }

    public function test_telegram_refuses_replayed_callback_and_mismatched_provider_flow(): void
    {
        $this->enableFakeVerifier();
        $start = $this->start();
        $this->fakeTelegram();
        $this->get('/auth/social/google/callback?'.http_build_query(['state' => $start['state'], 'code' => 'test-code']))
            ->assertRedirect(route('login'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        $start = $this->start();
        $this->get($this->callbackUrl($start))->assertRedirect(route('social.telegram.register'));
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
    }

    public function test_telegram_link_uses_post_local_get_before_external_redirect_and_can_unlink(): void
    {
        $this->enableFakeVerifier();
        $user = User::factory()->create(['password' => Hash::make('Secret123!')]);
        $this->actingAs($user, 'web');
        $this->post('/account/security/social/telegram/link', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('social');
        $this->post('/account/security/social/telegram/link', ['current_password' => 'Secret123!'])
            ->assertOk()->assertViewIs('auth.social-continue')->assertViewHas('continueUrl');
        $url = (string) $this->get('/account/security/social/telegram/continue')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://oauth.telegram.org/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->fakeTelegram();
        $this->get($this->callbackUrl($query))->assertRedirect(route('security.edit'));
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $user->id, 'provider' => 'telegram']);
        $this->delete('/account/security/social/telegram', ['current_password' => 'Secret123!'])
            ->assertRedirect(route('security.edit'));
        $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id, 'provider' => 'telegram']);
    }

    public function test_invalid_id_token_never_creates_or_links_an_account(): void
    {
        $this->enableFakeVerifier(reject: true);
        $start = $this->start();
        $this->fakeTelegram();
        $this->get($this->callbackUrl($start))->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_social_accounts', 0);
    }

    private function simulateUnavailableLibrary(): void
    {
        $this->app->instance(TelegramIdTokenVerifier::class, new class extends TelegramIdTokenVerifier
        {
            public function available(): bool
            {
                return false;
            }
        });
    }

    private function enableFakeVerifier(bool $reject = false): void
    {
        $this->app->instance(TelegramIdTokenVerifier::class, new class($reject) extends TelegramIdTokenVerifier
        {
            public function __construct(private readonly bool $reject) {}

            public function available(): bool
            {
                return true;
            }

            public function verify(string $idToken, string $clientId, string $nonce): array
            {
                if ($this->reject || $idToken !== 'signed-fixture' || $clientId !== '123456789' || strlen($nonce) < 32) {
                    throw new \RuntimeException('Invalid token fixture.');
                }

                return ['sub' => '987654321', 'name' => 'Telegram Player'];
            }
        });
        app(SocialAuthSettings::class)->setEnabled('telegram', true);
    }

    /** @return array<string,string> */
    private function start(): array
    {
        $url = (string) $this->get('/auth/social/telegram')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://oauth.telegram.org/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    private function fakeTelegram(): void
    {
        Http::fake(['https://oauth.telegram.org/token' => Http::response([
            'access_token' => 'server-only', 'id_token' => 'signed-fixture', 'token_type' => 'Bearer',
        ])]);
    }

    private function callbackUrl(array $start): string
    {
        return '/auth/social/telegram/callback?'.http_build_query(['state' => $start['state'], 'code' => 'test-code']);
    }
}
