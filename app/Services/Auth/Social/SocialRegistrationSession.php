<?php

namespace App\Services\Auth\Social;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/** Confirmed provider identity between OAuth callback and one-time CMS registration. */
final class SocialRegistrationSession
{
    private const KEY = 'social_auth.registration';

    private const TTL = 900;

    public function start(Request $request, SocialIdentity $identity): void
    {
        $request->session()->put(self::KEY, [
            'provider' => $identity->provider,
            'subject' => $identity->providerUserId,
            'email' => $identity->email,
            'assured' => $identity->emailOwnershipAssured,
            'issued' => now()->timestamp,
            'email_verification_hash' => null,
        ]);
    }

    /** @return array{provider:string,subject:string,email:string|null,assured:bool,issued:int,email_verification_hash:string|null}|null */
    public function get(Request $request): ?array
    {
        $pending = $request->session()->get(self::KEY);
        if (! is_array($pending) || ! is_string($pending['provider'] ?? null)
            || ! is_string($pending['subject'] ?? null)
            || ! is_numeric($pending['issued'] ?? null)
            || $pending['issued'] > now()->timestamp || now()->timestamp - $pending['issued'] > self::TTL) {
            $request->session()->forget(self::KEY);

            return null;
        }

        return $pending;
    }

    public function requestEmailVerification(Request $request, string $email): string
    {
        $pending = $this->get($request);
        if ($pending === null || $pending['assured']) {
            throw new RuntimeException('No pending verification.');
        }
        $pending['email'] = strtolower(trim($email));
        $pending['email_verification_hash'] = hash('sha256', $token = Str::random(64));
        $request->session()->put(self::KEY, $pending);

        return $token;
    }

    public function verifyEmail(Request $request, string $token): void
    {
        $pending = $this->get($request);
        if ($pending === null || ! is_string($pending['email'] ?? null)
            || ! is_string($pending['email_verification_hash'] ?? null)
            || ! hash_equals($pending['email_verification_hash'], hash('sha256', $token))) {
            throw new RuntimeException('Expired email confirmation.');
        }
        $pending['assured'] = true;
        $pending['email_verification_hash'] = null;
        $request->session()->put(self::KEY, $pending);
    }

    /** @return array{provider:string,subject:string,email:string,assured:bool,issued:int,email_verification_hash:string|null} */
    public function consume(Request $request): array
    {
        $pending = $this->get($request);
        if ($pending === null || ! $pending['assured'] || ! is_string($pending['email'] ?? null)) {
            throw new RuntimeException('Unverified social registration.');
        }
        $request->session()->forget(self::KEY);

        return $pending;
    }
}
