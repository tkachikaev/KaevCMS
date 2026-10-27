<?php

namespace KaevCMS\Modules\Donations\Providers;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\DeferredCheckoutProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;
use KaevCMS\Modules\Donations\Services\PayPalOrderOperations;
use KaevCMS\Modules\Donations\Services\PayPalOrdersApi;
use LogicException;

/** PayPal uses server-side Orders v2; incoming callbacks are handled by the separately verified webhook service. */
final class PayPalProvider implements DeferredCheckoutProvider
{
    public function code(): string { return 'paypal'; }

    public function name(): string { return 'PayPal'; }

    public function supportedCurrencies(): array { return app(PayPalAdminSettings::class)->supportedCurrencies(); }

    public function supportsTestMode(): bool { return false; }

    /** Credentials are stored encrypted by PayPalAdminSettings, never copied into generic driver config. */
    public function credentialFields(): array { return []; }

    public function assertConfiguration(array $credentials, string $mode): void
    {
        if ($mode !== 'live' || ! app(PayPalAdminSettings::class)->liveReady()) {
            throw new InvalidArgumentException('PayPal Live is not configured.');
        }
    }

    public function referenceForOrder(string $orderUuid): string
    {
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderUuid)) {
            throw new InvalidArgumentException('Invalid PayPal order reference.');
        }
        return $orderUuid;
    }

    public function callbackRouteName(): string { return 'payments.paypal.notify'; }

    public function referenceFromCallback(ProviderCallback $callback): string
    {
        throw new LogicException('PayPal events require independent webhook verification.');
    }

    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        throw new LogicException('PayPal settlement requires merchant API confirmation.');
    }

    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        throw new LogicException('PayPal checkout requires a prepared deferred REST attempt.');
    }

    public function createServerCheckout(PaymentIntent $intent, array $credentials, DonationPaymentPreparation $preparation): PaymentCheckout
    {
        $this->assertConfiguration($credentials, 'live');
        if ($preparation->provider_code !== 'paypal' || $preparation->mode !== 'live'
            || $preparation->provider_label !== $intent->orderUuid || $preparation->currency_code !== $intent->currencyCode
            || (int) $preparation->payer_amount_minor !== $intent->amountMinor) {
            throw new LogicException('Invalid PayPal prepared checkout.');
        }
        $settings = app(PayPalAdminSettings::class);
        if ($settings->selectedCurrency($intent->currencyCode) !== $intent->currencyExponent) {
            throw new LogicException('Unsupported PayPal currency.');
        }
        $apiCredentials = $settings->credentials('live');
        $attempt = app(PayPalAttempts::class)->reserveLive($preparation, $apiCredentials->merchantIdentity(), $settings->payeeId('live'));
        $returnUrl = route('modules.donations.orders.paypal.return', ['orderUuid' => $intent->orderUuid]);
        $cancelUrl = route('modules.donations.orders.show', ['orderUuid' => $intent->orderUuid]);
        $url = app(PayPalOrderOperations::class)->create((int) $attempt->id, $apiCredentials, $returnUrl, $cancelUrl);
        if ($url === null) {
            // A parallel worker is creating this remote order; never start another POST.
            throw new LogicException('PayPal order creation is already running or needs review.');
        }
        return new PaymentCheckout($attempt->paypal_order_id, $url, 'GET');
    }

    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void
    {
        $this->assertConfiguration($credentials, 'live');
        $attempt = PayPalAttempt::query()->where('order_id', function ($query) use ($reference): void {
            $query->select('id')->from('module_donation_orders')->where('order_uuid', $reference);
        })->first();
        if ($attempt === null || $attempt->purpose !== 'player' || $attempt->environment !== 'live'
            || $attempt->paypal_order_id === null || (int) $attempt->gross_amount_minor !== $intent->amountMinor
            || $attempt->currency_code !== $intent->currencyCode || $checkout->method !== 'GET'
            || $checkout->fields !== [] || ! PayPalOrdersApi::validApprovalHost($checkout->redirectUrl, 'live', $attempt->paypal_order_id)) {
            throw new LogicException('Untrusted PayPal checkout.');
        }
    }
}
