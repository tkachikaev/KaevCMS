<?php

namespace App\Services\Auth\Social;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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

        try {
            $jwks = Cache::get('social.telegram.oidc.jwks');
            if (! is_array($jwks)) {
                $jwks = $this->refreshKeys();
            }
            $keys = array_filter(JWK::parseKeySet($jwks, 'RS256'), static fn (Key $key): bool => $key->getAlgorithm() === 'RS256');
            $headers = new \stdClass;
            try {
                $decoded = JWT::decode($idToken, $keys, $headers);
            } catch (\UnexpectedValueException $exception) {
                // Unverified headers only select a refresh of our fixed endpoint, never an identity.
                $kid = $headers->kid ?? null;
                if (($headers->alg ?? null) !== 'RS256' || ! is_string($kid)
                    || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $kid) !== 1
                    || isset($keys[$kid])
                    || ! Cache::add('social.telegram.oidc.jwks.refresh', true, 30)) {
                    throw $exception;
                }
                $keys = JWK::parseKeySet($this->refreshKeys(), 'RS256');
                $decoded = JWT::decode($idToken, $keys);
            }
            $claims = (array) $decoded;
        } catch (Throwable) {
            // Never expose JWT exception payloads, tokens or key material in logs.
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
            || (array_key_exists('nbf', $claims) && (! is_int($claims['nbf']) || $claims['nbf'] > $now))
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 900
            || $claims['exp'] <= $claims['iat']
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['sub'] ?? null)
            || preg_match('/\A[0-9]{1,32}\z/', $claims['sub']) !== 1) {
            throw new RuntimeException('Telegram identity claims rejected.');
        }

        return $claims;
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function refreshKeys(): array
    {
        // No redirects or token-supplied jku/x5u: only the official HTTPS key endpoint.
        $response = Http::acceptJson()->timeout(8)->connectTimeout(4)
            ->withOptions(['allow_redirects' => false])->get(self::JWKS_URL);
        if (! $response->successful() || strlen($response->body()) > 65536) {
            throw new RuntimeException('Unable to load Telegram signing keys.');
        }
        $data = $response->json();
        if (! is_array($data) || ! is_array($data['keys'] ?? null)
            || count($data['keys']) < 1 || count($data['keys']) > 16) {
            throw new RuntimeException('Invalid Telegram signing key set.');
        }
        $keys = [];
        $seen = [];
        foreach ($data['keys'] as $key) {
            if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA'
                || ($key['alg'] ?? 'RS256') !== 'RS256' || ($key['use'] ?? 'sig') !== 'sig'
                || (isset($key['key_ops']) && (! is_array($key['key_ops']) || ! in_array('verify', $key['key_ops'], true)))
                || ! is_string($key['kid'] ?? null)
                || preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $key['kid']) !== 1
                || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
                continue;
            }
            if (isset($seen[$key['kid']])) {
                throw new RuntimeException('Ambiguous Telegram signing key set.');
            }
            $seen[$key['kid']] = true;
            // Keep public verification material only, with a pinned algorithm.
            $keys[] = ['kty' => 'RSA', 'kid' => $key['kid'], 'alg' => 'RS256', 'n' => $key['n'], 'e' => $key['e']];
        }
        if ($keys === []) {
            throw new RuntimeException('Telegram RS256 signing keys are missing.');
        }
        $jwks = ['keys' => $keys];
        JWK::parseKeySet($jwks, 'RS256');
        Cache::put('social.telegram.oidc.jwks', $jwks, 600);
        Cache::add('social.telegram.oidc.jwks.refresh', true, 30);

        return $jwks;
    }
}
