<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Discord OAuth2 Code + PKCE, fixed official endpoints; tokens never persist in the CMS. */
final class DiscordIdentityProvider implements SocialIdentityProvider
{
    private const AUTHORIZE_URL = 'https://discord.com/oauth2/authorize';

    private const TOKEN_URL = 'https://discord.com/api/v10/oauth2/token';

    private const PROFILE_URL = 'https://discord.com/api/v10/users/@me';

    private const ISSUER = 'https://discord.com';

    public function __construct(private readonly SocialAuthSettings $settings) {}

    public function name(): string
    {
        return SocialProviders::DISCORD;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
    {
        $credentials = $this->credentials();
        if ($state === '' || ! is_string($codeChallenge) || $codeChallenge === '') {
            throw new RuntimeException('Missing authorization safeguards.');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['redirect_uri'],
            'scope' => 'identify email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $callback */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
    {
        // Discord includes RFC 9207 `iss` in every redirect. Reject mix-up attacks before exchanging a code.
        if (! is_string($callback['iss'] ?? null) || ! hash_equals(self::ISSUER, $callback['iss'])
            || $code === '' || strlen($code) > 4096 || ! is_string($codeVerifier)
            || preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $codeVerifier) !== 1) {
            throw new RuntimeException('Invalid Discord authorization response.');
        }
        $credentials = $this->credentials();
        $response = Http::asForm()->acceptJson()->withBasicAuth($credentials['client_id'], $credentials['client_secret'])
            ->withoutRedirecting()->timeout(8)->connectTimeout(4)->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $credentials['redirect_uri'],
                'code_verifier' => $codeVerifier,
            ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Discord authorization exchange failed.');
        }
        $token = $response->json('access_token');
        $type = $response->json('token_type');
        $scopes = $response->json('scope');
        if (! is_string($token) || $token === '' || strlen($token) > 8192
            || ! is_string($type) || strcasecmp($type, 'Bearer') !== 0
            || ! is_string($scopes) || ! in_array('identify', preg_split('/\s+/', trim($scopes)), true)
            || ! in_array('email', preg_split('/\s+/', trim($scopes)), true)) {
            throw new RuntimeException('Discord returned an invalid token or insufficient scopes.');
        }

        $response = Http::withToken($token)->acceptJson()->withoutRedirecting()
            ->timeout(8)->connectTimeout(4)->get(self::PROFILE_URL);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Discord profile request failed.');
        }
        $profile = $response->json();
        if (! is_array($profile) || isset($profile['error'])) {
            throw new RuntimeException('Discord returned an invalid profile.');
        }
        // Discord snowflake IDs are decimal strings; never use usernames or email as identity keys.
        $subject = $profile['id'] ?? null;
        if (! is_string($subject) || preg_match('/\A[0-9]{1,20}\z/', $subject) !== 1) {
            throw new RuntimeException('Discord subject is missing.');
        }
        $email = $profile['email'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($email)) : null;

        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: $subject,
            email: $email,
            providerEmailVerified: $email !== null && ($profile['verified'] ?? null) === true,
            // Even an email verified by Discord needs an independent CMS confirmation for registration.
            emailOwnershipAssured: false,
            displayName: is_string($profile['global_name'] ?? null)
                ? mb_substr($profile['global_name'], 0, 255)
                : (is_string($profile['username'] ?? null) ? mb_substr($profile['username'], 0, 255) : null),
        );
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string} */
    private function credentials(): array
    {
        $values = $this->settings->credentialsFor($this->name());
        if (! is_array($values) || ! is_string($values['client_secret'] ?? null)
            || ! is_string($values['redirect_uri'] ?? null)) {
            throw new RuntimeException('Discord identity provider is unavailable.');
        }

        return $values;
    }
}
