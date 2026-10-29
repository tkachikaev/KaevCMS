<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** VK ID (id.vk.ru) Authorization Code + PKCE; never the legacy VKontakte OAuth API. */
final class VkIdentityProvider implements SocialIdentityProvider
{
    private const AUTHORIZE_URL = 'https://id.vk.ru/authorize';

    private const TOKEN_URL = 'https://id.vk.ru/oauth2/auth';

    private const PROFILE_URL = 'https://id.vk.ru/oauth2/user_info';

    public function __construct(private readonly SocialAuthSettings $settings) {}

    public function name(): string
    {
        return SocialProviders::VK;
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
            'scope' => 'email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            // VK ID web SDK sends this value in lowercase.
            'code_challenge_method' => 's256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $callback */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
    {
        $credentials = $this->credentials();
        $state = $callback['state'] ?? null;
        $deviceId = $callback['device_id'] ?? null;
        if ($code === '' || strlen($code) > 4096 || ! is_string($codeVerifier)
            || preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $codeVerifier) !== 1
            || ! is_string($state) || preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', $state) !== 1
            || ! is_string($deviceId) || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $deviceId) !== 1) {
            throw new RuntimeException('Invalid VK ID authorization response.');
        }

        // No client_secret for VK ID's PKCE web flow. No redirects to other hosts.
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(8)->connectTimeout(4)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $credentials['client_id'],
                'redirect_uri' => $credentials['redirect_uri'],
                'code_verifier' => $codeVerifier,
                'device_id' => $deviceId,
                'state' => $state,
            ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('VK ID code exchange failed.');
        }
        $data = $response->json();
        if (! is_array($data) || isset($data['error'])
            || ! is_string($data['state'] ?? null) || ! hash_equals($state, $data['state'])) {
            throw new RuntimeException('VK ID returned a mismatched state.');
        }
        $token = $data['access_token'] ?? null;
        if (! is_string($token) || $token === '' || strlen($token) > 8192) {
            throw new RuntimeException('VK ID access token missing.');
        }

        // The official web SDK posts the access token in the form body, never in a URL.
        $profileResponse = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(8)->connectTimeout(4)
            ->post(self::PROFILE_URL, [
                'client_id' => $credentials['client_id'],
                'access_token' => $token,
            ]);
        if (! $profileResponse->successful() || strlen($profileResponse->body()) > 32768) {
            throw new RuntimeException('VK ID user profile request failed.');
        }
        $profile = $profileResponse->json();
        $user = is_array($profile) ? ($profile['user'] ?? null) : null;
        if (! is_array($user) || isset($profile['error'])) {
            throw new RuntimeException('VK ID returned no trusted user.');
        }
        $subject = $user['user_id'] ?? null;
        if ((! is_string($subject) && ! is_int($subject))
            || preg_match('/\A[0-9]{1,160}\z/', (string) $subject) !== 1) {
            throw new RuntimeException('VK ID subject missing.');
        }
        // Reject conflicting subjects between the two server-side endpoints.
        $tokenSubject = $data['user_id'] ?? null;
        if ($tokenSubject !== null && (! is_string($tokenSubject) && ! is_int($tokenSubject)
            || ! hash_equals((string) $subject, (string) $tokenSubject))) {
            throw new RuntimeException('VK ID subject mismatch.');
        }
        $email = $user['email'] ?? null;
        $email = is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($email)) : null;
        $parts = array_filter([$user['first_name'] ?? null, $user['last_name'] ?? null], 'is_string');
        $name = trim(implode(' ', $parts));

        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: (string) $subject,
            email: $email,
            // VK ID does not provide an ownership-verification assertion for CMS email.
            providerEmailVerified: false,
            emailOwnershipAssured: false,
            displayName: $name !== '' ? $name : null,
        );
    }

    /** @return array{client_id:string,client_secret:string|null,redirect_uri:string} */
    private function credentials(): array
    {
        $values = $this->settings->credentialsFor($this->name());
        if (! is_array($values) || ! is_string($values['redirect_uri'] ?? null)) {
            throw new RuntimeException('VK ID provider is unavailable.');
        }

        return $values;
    }
}
