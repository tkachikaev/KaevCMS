<?php

namespace KaevCMS\Modules\Donations\Data;

/** Browser redirect is untrusted for payment confirmation. */
final readonly class PaymentCheckout
{
    public function __construct(
        public string $providerPaymentId,
        public string $redirectUrl,
    ) {}
}
