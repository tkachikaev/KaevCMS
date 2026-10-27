<?php

namespace KaevCMS\Modules\Donations\Data;

use InvalidArgumentException;

/** Never expose this value object to templates, logs, session or JSON responses. */
final readonly class PayPalApiCredentials
{
    public function __construct(
        public string $environment,
        public string $clientId,
        public string $clientSecret,
    ) {
        if (! in_array($environment, ['sandbox', 'live'], true)
            || $clientId === '' || strlen($clientId) > 512
            || $clientSecret === '' || strlen($clientSecret) > 1024) {
            throw new InvalidArgumentException('Invalid PayPal API credentials.');
        }
    }

    /** Configuration identity is stable across secret rotation; never contains credentials. */
    public function merchantIdentity(): string
    {
        return 'pp_'.hash('sha256', $this->environment."\0".$this->clientId);
    }

    /** Tokens must not be shared across modes OR rotated Client Secrets. */
    public function tokenCacheKey(): string
    {
        return 'donations:paypal:oauth:'.hash('sha256', $this->environment."\0".$this->clientId."\0".$this->clientSecret);
    }
}
