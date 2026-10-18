<?php

namespace Tests\Fixtures\Donations;

final class FakePaymentDriverBeta extends FakePaymentDriverAlpha
{
    public function code(): string
    {
        return 'fixture_beta';
    }

    public function name(): string
    {
        return 'Beta test provider';
    }

    public function supportedCurrencies(): array
    {
        return ['USD'];
    }
}
