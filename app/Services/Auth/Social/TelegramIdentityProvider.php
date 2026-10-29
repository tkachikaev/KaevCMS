<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Telegram Authorization Code + PKCE; the signed ID token is the only identity source. */
final class TelegramIdentityProvider implements SocialIdentityProvider
{
    private const AUTHORIZE_URL = 'https://oauth.telegram.org/auth';

    private const TOKEN_URL = 'https://oauth.telegram.org/token';

    public function __construct(
        private readonly SocialAuthSettings $settings,
        private readonly TelegramIdTokenVerifier $tokens,
    ) {}

    public function name(): string
    {
        return SocialProviders::TELEGRAM;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
    {
        $credentials = $this->credentials();
        if ($state === '' || $codeChallenge === null || $nonce === null) {
            throw new RuntimeException('Missing Telegram authorization safeguards.');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['redirect_uri'],
            'scope' => 'openid profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $callback */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
    {
        $credentials = $this->credentials();
        if (! $this->tokens->available() || $code === '' || strlen($code) > 4096
            || $codeVerifier === null || $nonce === null) {
            throw new RuntimeException('Telegram identity verification unavailable.');
        }
        // Never put authorization codes, client secret, or ID tokens into query parameters or logs.
        $response = Http::asForm()->acceptJson()->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
            ->timeout(8)->connectTimeout(4)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $credentials['redirect_uri'],
                'client_id' => $credentials['client_id'],
                'code_verifier' => $codeVerifier,
            ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Telegram identity exchange failed.');
        }
        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('Telegram identity token was not returned.');
        }
        $claims = $this->tokens->verify($idToken, $credentials['client_id'], $nonce);

        // Telegram OIDC returns no email; collect and verify it in the existing CMS flow.
        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: $claims['sub'],
            displayName: is_string($claims['name'] ?? null) ? mb_substr($claims['name'], 0, 255) : null,
        );
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string} */
    private function credentials(): array
    {
        $credentials = $this->settings->credentialsFor($this->name());
        if (! is_array($credentials) || ! is_string($credentials['client_secret'] ?? null)
            || ! is_string($credentials['redirect_uri'] ?? null)) {
            throw new RuntimeException('Telegram identity provider is unavailable.');
        }

        return $credentials;
    }
}
