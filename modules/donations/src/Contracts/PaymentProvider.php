<?php

namespace KaevCMS\Modules\Donations\Contracts;

use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;

/**
 * Implemented by explicitly installed, trusted PHP drivers. Never resolve driver classes
 * from a request or the database. No driver is bundled in C1/0.50.0.
 */
interface PaymentProvider
{
    public function code(): string;

    public function name(): string;

    /** @return list<string> ISO 4217 codes the driver's API actually accepts (not CMS tariffs). */
    public function supportedCurrencies(): array;

    public function supportsTestMode(): bool;

    /** @return array<string, array{label:string,secret:bool,required:bool}> */
    public function credentialFields(): array;

    /**
     * Throws for unusable configuration; must not leak credentials in exception messages.
     *
     * @param  array<string, string>  $credentials
     */
    public function assertConfiguration(array $credentials, string $mode): void;

    /**
     * The driver receives immutable order terms; amounts must never originate from the browser.
     *
     * @param  array<string, string>  $credentials
     */
    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout;

    /**
     * Verify provider-specific signature/credentials BEFORE returning a trusted result.
     *
     * @param  array<string, string>  $credentials
     */
    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment;
}
