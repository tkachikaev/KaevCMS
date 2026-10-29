<?php

namespace App\Services\Auth\Social;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/** Transient, session-bound OAuth intent. No provider tokens are persisted here. */
final class SocialFlowState
{
    private const SESSION_KEY = 'social_auth.pending';

    private const TTL_SECONDS = 900;

    public function start(Request $request, string $provider, string $purpose, ?User $user = null): SocialAuthorizationAttempt
    {
        SocialProviders::assertAllowed($provider);
        if (! in_array($purpose, ['login', 'link'], true) || ($purpose === 'link' && $user === null)) {
            throw new RuntimeException('Invalid social authorization purpose.');
        }

        $state = Str::random(64);
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $nonce = Str::random(48);
        $request->session()->put(self::SESSION_KEY, [
            'hash' => hash('sha256', $state),
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
            'provider' => $provider,
            'purpose' => $purpose,
            'user_id' => $user?->getKey(),
            'session_version' => $user?->auth_session_version,
            'started_at' => now()->timestamp,
        ]);

        return new SocialAuthorizationAttempt($state, $codeChallenge, $nonce);
    }

    /** @return array{provider: string, purpose: string, user_id: int|null, session_version: int|null, code_verifier: string, nonce: string} */
    public function consume(Request $request, string $provider, string $state, ?User $user = null): array
    {
        SocialProviders::assertAllowed($provider);
        $pending = $request->session()->pull(self::SESSION_KEY);
        if (! is_array($pending)
            || ! is_string($pending['hash'] ?? null)
            || ! hash_equals($pending['hash'], hash('sha256', $state))
            || ($pending['provider'] ?? null) !== $provider
            || ! is_string($pending['code_verifier'] ?? null)
            || ! is_string($pending['nonce'] ?? null)
            || ! is_numeric($pending['started_at'] ?? null)
            || (int) $pending['started_at'] > now()->timestamp
            || (now()->timestamp - (int) $pending['started_at']) > self::TTL_SECONDS) {
            throw new RuntimeException('Social authorization has expired or is invalid.');
        }

        $purpose = $pending['purpose'] ?? null;
        if (! in_array($purpose, ['login', 'link'], true)) {
            throw new RuntimeException('Invalid social authorization purpose.');
        }

        if ($purpose === 'link' && ($user === null
            || (int) $pending['user_id'] !== (int) $user->getKey()
            || (int) $pending['session_version'] !== (int) $user->fresh()?->auth_session_version)) {
            throw new RuntimeException('Social authorization is no longer valid for this account.');
        }

        return [
            'provider' => $provider,
            'purpose' => $purpose,
            'user_id' => $purpose === 'link' ? (int) $pending['user_id'] : null,
            'session_version' => $purpose === 'link' ? (int) $pending['session_version'] : null,
            'code_verifier' => (string) $pending['code_verifier'],
            'nonce' => (string) $pending['nonce'],
        ];
    }
}
