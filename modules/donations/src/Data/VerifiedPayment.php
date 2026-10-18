<?php

namespace KaevCMS\Modules\Donations\Data;

/** A driver returns this ONLY after cryptographic/API verification. Reconcile in C3. */
final readonly class VerifiedPayment
{
    public function __construct(
        public string $orderUuid,
        public string $providerPaymentId,
        public string $status,
        public string $currencyCode,
        public int $amountMinor, // payer debit; compare to order checkout amount in C3
        public ?int $receivedAmountMinor = null, // receiver net after fees
        public bool $testNotification = false, // NEVER credit this
        public string $environment = 'live', // test configuration must also never settle
    ) {}
}
