<?php

namespace KaevCMS\Modules\Donations\Services;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\PaymentProvider;
use LogicException;

/**
 * Installation-time PHP allowlist. C2 adds UnitPay here; DB entries cannot load classes.
 * Injectable list allows test drivers without including fake production providers.
 */
final class DonationProviderRegistry
{
    /** @var list<class-string<PaymentProvider>> */
    private const BUNDLED_DRIVERS = [];

    /** @param list<class-string<PaymentProvider>>|null $driverClasses */
    public function __construct(private readonly ?array $driverClasses = null) {}

    /** @return array<string,PaymentProvider> */
    public function all(): array
    {
        $drivers = [];
        foreach ($this->driverClasses ?? self::BUNDLED_DRIVERS as $class) {
            if (! is_string($class) || ! is_a($class, PaymentProvider::class, true)) {
                throw new LogicException('Invalid installed Donations payment driver.');
            }
            $driver = app($class);
            $code = $driver->code();
            if (! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $code) || isset($drivers[$code])) {
                throw new LogicException('Invalid or duplicated Donations driver identifier.');
            }
            foreach ($driver->supportedCurrencies() as $currency) {
                if (! is_string($currency) || ! preg_match('/\A[A-Z]{3}\z/D', $currency)) {
                    throw new LogicException('Invalid Donations driver currency declaration.');
                }
            }
            foreach ($driver->credentialFields() as $field => $spec) {
                if (! is_string($field) || ! preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D', $field)
                    || ! is_array($spec) || ! isset($spec['label'], $spec['secret'], $spec['required'])
                    || ! is_string($spec['label']) || ! is_bool($spec['secret']) || ! is_bool($spec['required'])) {
                    throw new LogicException('Invalid Donations driver configuration field.');
                }
            }
            $drivers[$code] = $driver;
        }
        ksort($drivers);
        return $drivers;
    }

    public function get(string $code): PaymentProvider
    {
        return $this->all()[$code] ?? throw new InvalidArgumentException('Unknown payment provider.');
    }
}
