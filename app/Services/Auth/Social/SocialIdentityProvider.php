<?php

namespace App\Services\Auth\Social;

/** Each adapter must validate its provider response before returning SocialIdentity. */
interface SocialIdentityProvider
{
    public function name(): string;

    /** Return the fixed provider authorize URL; flow state/PKCE belong to the core. */
    public function authorizationUrl(string $state, ?string $codeChallenge, ?string $nonce): string;

    /** Exchange the code on the backend and validate the external identity. */
    public function verifyAuthorizationCode(string $code, ?string $codeVerifier, ?string $nonce): SocialIdentity;
}
