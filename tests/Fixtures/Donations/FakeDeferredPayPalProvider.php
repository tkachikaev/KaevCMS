<?php

namespace Tests\Fixtures\Donations;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\DeferredCheckoutProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Services\PayPalAttempts;
use LogicException;

/** No HTTP, credentials or wallet: tests the deferred checkout seam only. */
final class FakeDeferredPayPalProvider implements DeferredCheckoutProvider
{
    public static int $remoteCreates = 0;

    public function code(): string
    {
        return 'paypal';
    }

    public function name(): string
    {
        return 'PayPal fixture';
    }

    public function supportedCurrencies(): array
    {
        return ['USD'];
    }

    public function supportsTestMode(): bool
    {
        return true;
    }

    public function credentialFields(): array
    {
        return ['api_secret' => ['label' => 'Key', 'secret' => true, 'required' => true]];
    }

    public function assertConfiguration(array $credentials, string $mode): void
    {
        if ($mode !== 'live' || ($credentials['api_secret'] ?? '') !== 'fixture-safe-key') {
            throw new InvalidArgumentException('Invalid fixture configuration.');
        }
    }

    public function callbackRouteName(): string
    {
        return 'payments.yoomoney.notify';
    }

    public function referenceForOrder(string $orderUuid): string
    {
        return $orderUuid;
    }

    public function referenceFromCallback(ProviderCallback $callback): string
    {
        throw new LogicException('No PayPal callback in iteration 1.');
    }

    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        throw new LogicException('No PayPal settlement in iteration 1.');
    }

    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        throw new LogicException('Remote provider must not use beginPayment inside prepare.');
    }

    public function createServerCheckout(PaymentIntent $intent, array $credentials, DonationPaymentPreparation $preparation): PaymentCheckout
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('External HTTP would run inside a SQL transaction.');
        }
        $attempts = app(PayPalAttempts::class);
        $attempt = $attempts->reserveLive($preparation, 'fixture_merchant_v1', 'MERCHANTABC123');
        if ($attempts->claimCreate((int) $attempt->id)) {
            self::$remoteCreates++;
            $attempts->markCreated((int) $attempt->id, 'PAYPAL-ORDER-1');
        }

        return new PaymentCheckout('PAYPAL-ORDER-1', 'https://www.paypal.com/checkoutnow?token=PAYPAL-ORDER-1', 'GET', []);
    }

    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void
    {
        if ($checkout->method !== 'GET' || $reference !== $intent->orderUuid
            || $checkout->redirectUrl !== 'https://www.paypal.com/checkoutnow?token=PAYPAL-ORDER-1') {
            throw new InvalidArgumentException('Invalid fixture checkout.');
        }
    }
}
