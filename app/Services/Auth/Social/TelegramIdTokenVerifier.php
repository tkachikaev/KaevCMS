<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Verify Telegram OIDC identity using an owner-approved, maintained JWT/JWKS library. */
class TelegramIdTokenVerifier
{
    private const JWKS_URL = 'https://oauth.telegram.org/.well-known/jwks.json';

    public function available(): bool
    {
        return class_exists('Firebase\JWT\JWT') && class_exists('Firebase\JWT\JWK');
    }

    /** @return array<string,mixed> */
    public function verify(string $idToken, string $clientId, string $nonce): array
    {
        if (! $this->available() || $idToken === '' || strlen($idToken) > 16384
            || substr_count($idToken, '.') !== 2 || $nonce === '') {
            throw new RuntimeException('Telegram identity verification unavailable or malformed.');
        }

        // Only Telegram's fixed JWKS origin is trusted. Never honor token-supplied jku/x5u URLs.
        $jwks = Cache::remember('social.telegram.oidc.jwks', 600, static function (): array {
            $response = Http::acceptJson()->timeout(8)->connectTimeout(4)->get(self::JWKS_URL);
            if (! $response->successful() || strlen($response->body()) > 65536) {
                throw new RuntimeException('Unable to load Telegram signing keys.');
            }
            $data = $response->json();
            if (! is_array($data) || ! is_array($data['keys'] ?? null)
                || count($data['keys']) < 1 || count($data['keys']) > 16) {
                throw new RuntimeException('Invalid Telegram signing key set.');
            }

            return $data;
        });

        // Our integration accepts RS256 only. Other algorithms must not be silently negotiated.
        $keys = [];
        foreach ($jwks['keys'] as $key) {
            if (is_array($key) && ($key['kty'] ?? null) === 'RSA'
                && ($key['alg'] ?? 'RS256') === 'RS256'
                && ($key['use'] ?? 'sig') === 'sig'
                && is_string($key['kid'] ?? null)
                && preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $key['kid']) === 1
                && is_string($key['n'] ?? null) && is_string($key['e'] ?? null)) {
                $key['alg'] = 'RS256';
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new RuntimeException('Telegram RS256 signing keys are missing.');
        }
        try {
            // Dynamic calls keep PHPStan compatible before the owner approves a new Composer dependency.
            $keySet = call_user_func(['Firebase\JWT\JWK', 'parseKeySet'], ['keys' => $keys], 'RS256');
            $decoded = call_user_func(['Firebase\JWT\JWT', 'decode'], $idToken, $keySet);
            $claims = (array) $decoded;
        } catch (Throwable) {
            // No fallback to unsigned/unverified claims, cached user info, or caller input.
            throw new RuntimeException('Invalid Telegram identity token.');
        }
        $now = time();
        $audience = $claims['aud'] ?? null;
        $validAudience = is_string($audience) && hash_equals($clientId, $audience);
        if (is_array($audience)) {
            $validAudience = count($audience) > 0 && count($audience) <= 8
                && in_array($clientId, $audience, true) && count(array_filter($audience, 'is_string')) === count($audience)
                && is_string($claims['azp'] ?? null) && hash_equals($clientId, $claims['azp']);
        }
        if (($claims['iss'] ?? null) !== 'https://oauth.telegram.org'
            || ! $validAudience
            || (isset($claims['azp']) && (! is_string($claims['azp']) || ! hash_equals($clientId, $claims['azp'])))
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 900
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['sub'] ?? null)
            || preg_match('/\A[0-9]{1,32}\z/', $claims['sub']) !== 1) {
            throw new RuntimeException('Telegram identity claims rejected.');
        }

        return $claims;
    }
}
