<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Yandex ID OAuth: Authorization Code + PKCE, trusted fixed endpoints and server-side profile fetch. */
final class YandexIdentityProvider implements SocialIdentityProvider
{
    private const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';

    private const TOKEN_URL = 'https://oauth.yandex.ru/token';

    private const PROFILE_URL = 'https://login.yandex.ru/info';

    public function __construct(private readonly SocialAuthSettings $settings) {}

    public function name(): string
    {
        return SocialProviders::YANDEX;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
    {
        $credentials = $this->credentials();
        if ($state === '' || $codeChallenge === null || $codeChallenge === '') {
            throw new RuntimeException('Missing authorization safeguards.');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['redirect_uri'],
            'scope' => 'login:email login:info',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $callback */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
    {
        $credentials = $this->credentials();
        if ($code === '' || strlen($code) > 4096 || ! is_string($codeVerifier)
            || preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $codeVerifier) !== 1) {
            throw new RuntimeException('Invalid authorization response.');
        }

        $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(8)->connectTimeout(4)->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'code_verifier' => $codeVerifier,
        ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Identity provider exchange failed.');
        }
        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 8192) {
            throw new RuntimeException('Missing provider access token.');
        }

        // Never send the OAuth token in a query parameter: Yandex supports an Authorization header.
        $profile = Http::withHeaders(['Authorization' => 'OAuth '.$accessToken])
            ->acceptJson()->withoutRedirecting()->timeout(8)->connectTimeout(4)->get(self::PROFILE_URL, ['format' => 'json']);
        if (! $profile->successful() || strlen($profile->body()) > 32768) {
            throw new RuntimeException('Identity provider profile request failed.');
        }
        $data = $profile->json();
        if (! is_array($data) || ! is_string($data['client_id'] ?? null)
            || ! hash_equals($credentials['client_id'], $data['client_id'])) {
            throw new RuntimeException('Identity provider audience mismatch.');
        }
        // Yandex documents `id` as the unique user ID and `psuid` as client-specific.
        // Use only `id` as the permanent identity namespace; never silently switch to psuid.
        $id = $data['id'] ?? null;
        if ((! is_string($id) && ! is_int($id))
            || preg_match('/\A[0-9]{1,160}\z/', (string) $id) !== 1) {
            throw new RuntimeException('Identity provider subject missing.');
        }
        $email = $data['default_email'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($email)) : null;

        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: (string) $id,
            email: $email,
            // JSON profile does not provide an explicit ownership-verification assertion.
            // CMS must verify the email itself before registration, including @yandex.ru.
            providerEmailVerified: false,
            emailOwnershipAssured: false,
            displayName: is_string($data['display_name'] ?? null) ? $data['display_name'] : null,
        );
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string} */
    private function credentials(): array
    {
        $values = $this->settings->credentialsFor($this->name());
        if (! is_array($values) || ! is_string($values['client_secret'] ?? null)
            || ! is_string($values['redirect_uri'] ?? null)) {
            throw new RuntimeException('Identity provider is unavailable.');
        }

        return $values;
    }
}
