<?php

namespace KaevCMS\Modules\Donations\Contracts;

use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;

/**
 * Opt-in contract for a fully reviewed payment flow. Installed PaymentProvider
 * drivers without these safeguards can still be configured but cannot accept
 * player payments. Callback references are UNTRUSTED routing hints only.
 */
interface RoutablePaymentProvider extends PaymentProvider
{
    /** Immutable provider reference, stable for the same CMS order. */
    public function referenceForOrder(string $orderUuid): string;

    /** Extract an untrusted reference; signature verification MUST follow. */
    public function referenceFromCallback(ProviderCallback $callback): string;

    /** A statically registered, trusted callback route name (not a DB-supplied URL). */
    public function callbackRouteName(): string;

    /**
     * Reject untrusted checkout destinations, method, receiver and altered amounts.
     *
     * @param array<string,string> $credentials
     */
    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void;
}
