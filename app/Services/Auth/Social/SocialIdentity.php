<?php

namespace App\Services\Auth\Social;

use InvalidArgumentException;

/** Verified external subject returned by an adapter; never treat email as the identity key. */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email = null,
        public bool $providerEmailVerified = false,
        public bool $emailOwnershipAssured = false,
        public ?string $displayName = null,
    ) {
        SocialProviders::assertAllowed($provider);

        if ($providerUserId === '' || strlen($providerUserId) > 160 || trim($providerUserId) !== $providerUserId) {
            throw new InvalidArgumentException('Invalid provider subject.');
        }

        if ($emailOwnershipAssured && (! $providerEmailVerified || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Email assurance needs a provider-verified address.');
        }
    }
}
