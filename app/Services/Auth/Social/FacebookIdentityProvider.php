<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Server-side Facebook Login: authorization code, fixed Graph endpoints, app secret and CSRF state. */
final class FacebookIdentityProvider implements SocialIdentityProvider
{
    // Pin Graph API endpoints to one supported version; revisit on Meta API deprecations.
    private const GRAPH_VERSION = 'v25.0';

    private const AUTHORIZE_URL = 'https://www.facebook.com/'.self::GRAPH_VERSION.'/dialog/oauth';

    private const TOKEN_URL = 'https://graph.facebook.com/'.self::GRAPH_VERSION.'/oauth/access_token';

    private const PROFILE_URL = 'https://graph.facebook.com/'.self::GRAPH_VERSION.'/me';

    public function __construct(private readonly SocialAuthSettings $settings) {}

    public function name(): string
    {
        return SocialProviders::FACEBOOK;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
    {
        $credentials = $this->credentials();
        if ($state === '' || strlen($state) > 256) {
            throw new RuntimeException('Missing authorization state.');
        }

        // Facebook's confidential web code flow relies on a server-held app secret and
        // the one-use session-bound state. Do not assume the Graph web endpoint supports PKCE.
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'email',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, string> $callback */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce, array $callback = []): SocialIdentity
    {
        if ($code === '' || strlen($code) > 4096) {
            throw new RuntimeException('Invalid Facebook authorization code.');
        }
        $credentials = $this->credentials();

        $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(8)->connectTimeout(4)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'redirect_uri' => $credentials['redirect_uri'],
                'code' => $code,
            ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Facebook authorization exchange failed.');
        }
        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 8192) {
            throw new RuntimeException('Facebook returned an invalid access token.');
        }
        $tokenType = $response->json('token_type');
        if ($tokenType !== null && (! is_string($tokenType) || strcasecmp($tokenType, 'bearer') !== 0)) {
            throw new RuntimeException('Facebook returned an unexpected token type.');
        }

        // Meta may omit email (phone-only account, missing permissions, etc.).
        // Never use an email as the provider subject, or trust it to claim an existing CMS account.
        $profile = Http::withToken($accessToken)->acceptJson()->withoutRedirecting()
            ->timeout(8)->connectTimeout(4)->get(self::PROFILE_URL, [
                'fields' => 'id,name,email',
                'appsecret_proof' => hash_hmac('sha256', $accessToken, $credentials['client_secret']),
            ]);
        if (! $profile->successful() || strlen($profile->body()) > 32768) {
            throw new RuntimeException('Facebook profile request failed.');
        }
        $data = $profile->json();
        if (! is_array($data) || array_is_list($data) || array_key_exists('error', $data)) {
            throw new RuntimeException('Facebook returned an invalid profile.');
        }
        // Meta's app-scoped Facebook User ID is a decimal string.
        $id = $data['id'] ?? null;
        if (! is_string($id) || preg_match('/\A[0-9]{1,160}\z/', $id) !== 1) {
            throw new RuntimeException('Facebook subject missing.');
        }
        $email = $data['email'] ?? null;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($email)) : null;

        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: $id,
            email: $email,
            providerEmailVerified: false,
            // Graph /me has no reliable CMS-usable email ownership assurance claim.
            emailOwnershipAssured: false,
            displayName: is_string($data['name'] ?? null) ? mb_substr($data['name'], 0, 255) : null,
        );
    }

    /** @return array{client_id:string,client_secret:string,redirect_uri:string} */
    private function credentials(): array
    {
        $values = $this->settings->credentialsFor($this->name());
        if (! is_array($values) || ! is_string($values['client_secret'] ?? null)
            || $values['client_secret'] === '' || ! is_string($values['redirect_uri'] ?? null)
            || $values['redirect_uri'] === '') {
            throw new RuntimeException('Facebook identity provider is unavailable.');
        }

        return $values;
    }
}
