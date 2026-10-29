<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Server-side Google OIDC: fixed endpoints, PKCE and an OpenSSL-verified ID token. */
final class GoogleIdentityProvider implements SocialIdentityProvider
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CERTIFICATES_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    public function __construct(private readonly SocialAuthSettings $settings) {}

    public function name(): string
    {
        return SocialProviders::GOOGLE;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string
    {
        $credentials = $this->credentials();
        if ($codeChallenge === null || $nonce === null) {
            throw new RuntimeException('Missing authorization safeguards.');
        }

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $credentials['redirect_uri'],
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce): SocialIdentity
    {
        $credentials = $this->credentials();
        if ($code === '' || strlen($code) > 4096 || $codeVerifier === null || $nonce === null) {
            throw new RuntimeException('Invalid authorization response.');
        }

        $response = Http::asForm()->acceptJson()->timeout(8)->connectTimeout(4)->post(self::TOKEN_URL, [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $credentials['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ]);
        if (! $response->successful() || strlen($response->body()) > 32768) {
            throw new RuntimeException('Identity provider exchange failed.');
        }
        $token = $response->json('id_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Missing identity token.');
        }
        $claims = $this->verifyIdToken($token, $credentials['client_id'], $nonce);
        $email = $claims['email'] ?? null;
        $verified = ($claims['email_verified'] ?? null) === true
            || ($claims['email_verified'] ?? null) === 'true';
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($email)) : null;
        $domain = $email !== null ? substr(strrchr($email, '@'), 1) : '';
        $workspace = is_string($claims['hd'] ?? null) && $domain !== ''
            && hash_equals(strtolower($claims['hd']), $domain);
        $assured = $verified && $email !== null && ($domain === 'gmail.com' || $workspace);

        return new SocialIdentity(
            provider: $this->name(),
            providerUserId: $claims['sub'],
            email: $email,
            providerEmailVerified: $verified,
            emailOwnershipAssured: $assured,
            displayName: is_string($claims['name'] ?? null) ? $claims['name'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function verifyIdToken(string $token, string $clientId, string $nonce): array
    {
        if (strlen($token) > 16384 || substr_count($token, '.') !== 2) {
            throw new RuntimeException('Invalid identity token.');
        }
        [$header, $payload, $signature] = explode('.', $token);
        $headerData = json_decode($this->decode($header), true);
        $claims = json_decode($this->decode($payload), true);
        if (! is_array($headerData) || ! is_array($claims)
            || ($headerData['alg'] ?? null) !== 'RS256'
            || ! is_string($headerData['kid'] ?? null)
            || preg_match('/\A[a-zA-Z0-9_-]{1,120}\z/', $headerData['kid']) !== 1) {
            throw new RuntimeException('Invalid identity token header.');
        }
        $certificates = Cache::remember('social.google.oidc.certificates', 600, function (): array {
            $response = Http::acceptJson()->timeout(8)->connectTimeout(4)->get(self::CERTIFICATES_URL);
            if (! $response->successful() || strlen($response->body()) > 65536) {
                throw new RuntimeException('Unable to fetch provider certificates.');
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('Invalid provider certificates.');
            }

            return $data;
        });
        $certificate = $certificates[$headerData['kid']] ?? null;
        if (! is_string($certificate) || ! str_contains($certificate, 'BEGIN CERTIFICATE')
            || openssl_verify($header.'.'.$payload, $this->decode($signature), $certificate, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Invalid identity token signature.');
        }
        $now = time();
        if (! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ! is_string($claims['aud'] ?? null) || ! hash_equals($clientId, $claims['aud'])
            || (isset($claims['azp']) && (! is_string($claims['azp']) || ! hash_equals($clientId, $claims['azp'])))
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 160) {
            throw new RuntimeException('Identity token claims rejected.');
        }

        return $claims;
    }

    private function decode(string $encoded): string
    {
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            throw new RuntimeException('Invalid token encoding.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid token encoding.');
        }

        return $decoded;
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
