<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\Auth\Social\TelegramIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TelegramJwtVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const JWKS = 'https://oauth.telegram.org/.well-known/jwks.json';

    /** @var array<string, array{private: string, jwk: array<string, string>}> */
    private static array $signers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Http::fake([self::JWKS => Http::response(['keys' => [$this->signer('current')['jwk']]])]);
    }

    public function test_real_rs256_signature_and_optional_nbf_are_verified_and_keys_cached(): void
    {
        $verifier = app(TelegramIdTokenVerifier::class);
        $this->assertTrue($verifier->available());
        $claims = $this->claims() + ['nbf' => time() - 1];
        $this->assertSame('987654321', $verifier->verify($this->token($claims), '123456789', 'expected-nonce')['sub']);
        $verifier->verify($this->token($claims), '123456789', 'expected-nonce');
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_signed_claims_are_rejected(string $field, mixed $value): void
    {
        $claims = $this->claims();
        if ($value === '__missing__') {
            unset($claims[$field]);
        } else {
            $claims[$field] = $value;
        }
        $this->expectException(RuntimeException::class);
        app(TelegramIdTokenVerifier::class)->verify($this->token($claims), '123456789', 'expected-nonce');
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidClaims(): array
    {
        return [
            'issuer' => ['iss', 'https://attacker.example'],
            'audience' => ['aud', 'other-client'],
            'array audience without azp' => ['aud', ['123456789', 'other-client']],
            'azp' => ['azp', 'other-client'],
            'nonce' => ['nonce', 'other-nonce'],
            'subject missing' => ['sub', '__missing__'],
            'subject numeric' => ['sub', 123],
            'subject invalid' => ['sub', '../user'],
            'expiry missing' => ['exp', '__missing__'],
            'expiry past' => ['exp', time() - 60],
            'expiry string' => ['exp', (string) (time() + 300)],
            'iat missing' => ['iat', '__missing__'],
            'iat old' => ['iat', time() - 1000],
            'iat future' => ['iat', time() + 300],
            'nbf future' => ['nbf', time() + 300],
            'nbf string' => ['nbf', '0'],
            'nbf null' => ['nbf', null],
        ];
    }

    public function test_wrong_signing_key_and_corrupted_signature_are_rejected(): void
    {
        foreach ([$this->token($this->claims(), 'attacker', 'current'), $this->token($this->claims()).'broken'] as $token) {
            try {
                app(TelegramIdTokenVerifier::class)->verify($token, '123456789', 'expected-nonce');
                $this->fail('A forged signature was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Telegram identity token.', $exception->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_algorithm_confusion_is_rejected_without_following_token_key_urls(): void
    {
        $token = JWT::encode($this->claims(), str_repeat('secret', 10), 'HS256', 'current', ['jku' => 'https://attacker.example/keys']);
        $this->expectException(RuntimeException::class);
        app(TelegramIdTokenVerifier::class)->verify($token, '123456789', 'expected-nonce');
    }

    public function test_rotated_unknown_key_is_refetched_once_and_used_only_after_verification(): void
    {
        Cache::put('social.telegram.oidc.jwks', ['keys' => [$this->signer('old')['jwk']]], 600);
        $this->assertSame('987654321', app(TelegramIdTokenVerifier::class)->verify($this->token($this->claims()), '123456789', 'expected-nonce')['sub']);
        Http::assertSentCount(1);
    }

    public function test_unknown_kids_cannot_force_unbounded_key_refreshes(): void
    {
        Cache::put('social.telegram.oidc.jwks', ['keys' => [$this->signer('current')['jwk']]], 600);
        foreach (['unknown-one', 'unknown-two', 'unknown-three'] as $kid) {
            try {
                app(TelegramIdTokenVerifier::class)->verify($this->token($this->claims(), 'attacker', $kid), '123456789', 'expected-nonce');
                $this->fail('Unknown signing key was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidKeyResponses')]
    public function test_jwks_failures_reject_authentication(mixed $body, int $status): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::JWKS => Http::response($body, $status)]);
        $this->expectException(RuntimeException::class);
        app(TelegramIdTokenVerifier::class)->verify($this->token($this->claims()), '123456789', 'expected-nonce');
    }

    /** @return array<string, array{mixed, int}> */
    public static function invalidKeyResponses(): array
    {
        return ['unavailable' => [[], 503], 'redirect' => [[], 302], 'invalid json' => ['not-json', 200], 'empty keys' => [['keys' => []], 200], 'oversized' => [str_repeat('x', 65537), 200]];
    }

    public function test_network_timeout_rejects_authentication(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([self::JWKS => Http::failedConnection()]);
        $this->expectException(RuntimeException::class);
        app(TelegramIdTokenVerifier::class)->verify($this->token($this->claims()), '123456789', 'expected-nonce');
    }

    public function test_ambiguous_or_unsuitable_signing_keys_are_rejected(): void
    {
        $key = $this->signer('current')['jwk'];
        foreach ([[$key, $key], [array_replace($key, ['use' => 'enc'])], [array_replace($key, ['alg' => 'RS384'])], [array_replace($key, ['key_ops' => ['sign']])]] as $keys) {
            Cache::flush();
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([self::JWKS => Http::response(['keys' => $keys])]);
            try {
                app(TelegramIdTokenVerifier::class)->verify($this->token($this->claims()), '123456789', 'expected-nonce');
                $this->fail('Unsuitable keys must not verify an identity.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Telegram identity token.', $exception->getMessage());
            }
        }
    }

    public function test_real_signed_token_cannot_log_in_a_blocked_user(): void
    {
        config()->set('app.url', 'https://cms.example.test');
        $settings = app(SocialAuthSettings::class);
        $settings->updateCredentials('telegram', '123456789', 'test-secret');
        $settings->setEnabled('telegram', true);
        $user = User::factory()->create(['is_active' => false]);
        $user->socialAccounts()->create(['provider' => 'telegram', 'provider_user_id' => '987654321']);
        $start = $this->get('/auth/social/telegram')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        Http::fake(['https://oauth.telegram.org/token' => Http::response(['id_token' => $this->token(array_replace($this->claims(), ['nonce' => $query['nonce']]))])]);
        $this->get('/auth/social/telegram/callback?'.http_build_query(['state' => $query['state'], 'code' => 'blocked-user-code']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('social');
        $this->assertGuest('web');
        $this->assertGuest('admin');
        $this->assertDatabaseCount('user_social_accounts', 1);
    }

    public function test_real_signed_flow_logs_in_once_and_cannot_reuse_token_in_new_flow(): void
    {
        config()->set('app.url', 'https://cms.example.test');
        $settings = app(SocialAuthSettings::class);
        $settings->updateCredentials('telegram', '123456789', 'test-secret');
        $settings->setEnabled('telegram', true);
        $user = User::factory()->create();
        $user->socialAccounts()->create(['provider' => 'telegram', 'provider_user_id' => '987654321']);
        $start = $this->get('/auth/social/telegram')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $query);
        $claims = array_replace($this->claims(), ['nonce' => $query['nonce']]);
        Http::fake(['https://oauth.telegram.org/token' => Http::response(['id_token' => $this->token($claims)])]);
        $callback = '/auth/social/telegram/callback?'.http_build_query(['state' => $query['state'], 'code' => 'one-time-code']);
        $this->get($callback)->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
        $this->post('/logout');
        $this->get($callback)->assertRedirect(route('login'));
        $start = $this->get('/auth/social/telegram')->assertRedirect();
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $next);
        $this->get('/auth/social/telegram/callback?'.http_build_query(['state' => $next['state'], 'code' => 'second-code']))->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->assertDatabaseCount('user_social_accounts', 1);
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return ['iss' => 'https://oauth.telegram.org', 'aud' => '123456789', 'sub' => '987654321', 'exp' => time() + 300, 'iat' => time() - 1, 'nonce' => 'expected-nonce'];
    }

    /** @param array<string, mixed> $claims */
    private function token(array $claims, string $signer = 'current', ?string $kid = null): string
    {
        return JWT::encode($claims, $this->signer($signer)['private'], 'RS256', $kid ?? $signer);
    }

    /** @return array{private: string, jwk: array<string, string>} */
    private function signer(string $kid): array
    {
        if (! isset(self::$signers[$kid])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key);
            openssl_pkey_export($key, $private);
            $details = openssl_pkey_get_details($key);
            self::$signers[$kid] = ['private' => $private, 'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]];
        }

        return self::$signers[$kid];
    }
}
