<?php

namespace KaevCMS\Modules\Donations\Contracts;

use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;

/**
 * REST checkout contract: preparation records immutable terms but MUST NOT call
 * the remote Create Order API. Execution is allowed only after SQL commit.
 * Implementations must reserve a stable attempt before making HTTP requests.
 */
interface DeferredCheckoutProvider extends RoutablePaymentProvider
{
    /** @param array<string,string> $credentials */
    public function createServerCheckout(
        PaymentIntent $intent,
        array $credentials,
        DonationPaymentPreparation $preparation,
    ): PaymentCheckout;
}
