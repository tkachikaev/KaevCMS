<?php

namespace KaevCMS\Modules\Donations\Data;

/** Server-generated snapshot; the driver must not change price or currency. */
final readonly class PaymentIntent
{
    public function __construct(
        public string $orderUuid,
        public int $coins,
        public string $currencyCode,
        public int $amountMinor,
        public int $currencyExponent,
        public string $notificationUrl,
        public string $returnUrl,
    ) {}
}
