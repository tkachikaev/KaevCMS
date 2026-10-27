<?php

namespace Tests\Fixtures\Donations;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Exceptions\InvalidPaymentSignature;

/** Test-only provider: exercises the shared payment path without FreeKassa or real payments. */
final class FakeRoutablePaymentProvider implements RoutablePaymentProvider
{
    public function code(): string
    {
        return 'fixture_gamma';
    }

    public function name(): string
    {
        return 'Fixture gamma';
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
        return ['api_secret' => ['label' => 'Secret', 'secret' => true, 'required' => true]];
    }

    public function assertConfiguration(array $credentials, string $mode): void
    {
        if (! in_array($mode, ['test', 'live'], true) || ! is_string($credentials['api_secret'] ?? null)
            || strlen($credentials['api_secret']) < 8) {
            throw new InvalidArgumentException('Invalid fixture configuration.');
        }
    }

    public function callbackRouteName(): string
    {
        // Reuse an installed route for the test-only preparation DTO. The real
        // endpoint always explicitly routes to YooMoney, never to this provider.
        return 'payments.yoomoney.notify';
    }

    public function referenceForOrder(string $orderUuid): string
    {
        return 'gamma-'.strtolower($orderUuid);
    }

    public function referenceFromCallback(ProviderCallback $callback): string
    {
        parse_str($callback->rawBody, $values);
        $reference = $values['reference'] ?? null;
        if (! is_string($reference) || ! preg_match('/\Agamma-[0-9a-f-]{36}\z/D', $reference)) {
            throw new InvalidArgumentException('Invalid fixture payment reference.');
        }

        return $reference;
    }

    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        $this->assertConfiguration($credentials, $mode);

        return new PaymentCheckout(null, 'https://payments.example.test/checkout', 'GET', [
            'reference' => $this->referenceForOrder($intent->orderUuid),
            'amount' => (string) $intent->amountMinor,
        ]);
    }

    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void
    {
        if ($checkout->redirectUrl !== 'https://payments.example.test/checkout' || $checkout->method !== 'GET'
            || ($checkout->fields['reference'] ?? null) !== $reference
            || ($checkout->fields['amount'] ?? null) !== (string) $intent->amountMinor) {
            throw new InvalidArgumentException('Invalid fixture checkout.');
        }
    }

    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        $this->assertConfiguration($credentials, $mode);
        parse_str($callback->rawBody, $values);
        $reference = $this->referenceFromCallback($callback);
        $operation = $values['operation'] ?? null;
        $amount = $values['amount'] ?? null;
        $signature = $values['signature'] ?? null;
        if (! is_string($operation) || ! preg_match('/\A[A-Z0-9_-]{1,40}\z/D', $operation)
            || ! is_string($amount) || ! preg_match('/\A[1-9][0-9]{0,14}\z/D', $amount)
            || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{64}\z/D', $signature)) {
            throw new InvalidArgumentException('Malformed fixture callback.');
        }
        $expected = hash_hmac('sha256', $reference.'|'.$operation.'|'.$amount, $credentials['api_secret']);
        if (! hash_equals($expected, $signature)) {
            throw new InvalidPaymentSignature('Invalid fixture signature.');
        }

        return new VerifiedPayment(
            substr($reference, 6), $operation, 'received_unsettled', 'USD', (int) $amount,
            (int) $amount, false, $mode,
        );
    }
}
