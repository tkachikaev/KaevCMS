<?php

namespace App\Services\Auth\Social;

/** Only public authorization-request values. Verifier stays inside the session. */
final readonly class SocialAuthorizationAttempt
{
    public function __construct(
        public string $state,
        public string $codeChallenge,
        public string $nonce,
    ) {}
}
