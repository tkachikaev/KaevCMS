<?php

namespace KaevCMS\Modules\Donations\Data;

/**
 * Prepared checkout description, NOT proof of settlement. Fields are rendered
 * only through the C5 trusted, default-disabled checkout gate.
 */
final readonly class PaymentCheckout
{
    /** @param array<string,string> $fields */
    public function __construct(
        public ?string $providerPaymentId,
        public string $redirectUrl,
        public string $method = 'GET',
        public array $fields = [],
    ) {}
}
