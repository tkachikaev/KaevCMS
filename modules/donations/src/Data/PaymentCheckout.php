<?php

namespace KaevCMS\Modules\Donations\Data;

/**
 * Prepared checkout description. NOT proof of settlement. POST fields may only be
 * rendered after C3 enables an authenticated, signed, persistent payment flow.
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
