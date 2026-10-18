<?php

namespace Tests\Fixtures\Donations;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\PaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use RuntimeException;

class FakePaymentDriverAlpha implements PaymentProvider
{
    public function code(): string
    {
        return 'fixture_alpha';
    }

    public function name(): string
    {
        return 'Alpha test provider';
    }

    public function supportedCurrencies(): array
    {
        return ['RUB', 'USD'];
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
        if (! is_string($credentials['api_secret'] ?? null) || $credentials['api_secret'] === '') {
            throw new InvalidArgumentException('Missing credential.');
        }
    }

    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        throw new RuntimeException('Test fixture only.');
    }

    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        throw new RuntimeException('Test fixture only.');
    }
}
