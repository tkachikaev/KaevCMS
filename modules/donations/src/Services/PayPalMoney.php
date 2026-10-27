<?php

namespace KaevCMS\Modules\Donations\Services;

use InvalidArgumentException;

/** Currency-scale rules applied when communicating with PayPal, with exact integer arithmetic. */
final class PayPalMoney
{
    public static function assertExponent(string $currency, int $exponent): void
    {
        if (! preg_match('/\A[A-Z]{3}\z/D', $currency)
            || ! in_array($exponent, [0, 2], true)
            || (in_array($currency, ['HUF', 'JPY', 'TWD'], true) && $exponent !== 0)) {
            throw new InvalidArgumentException('Unsupported PayPal amount precision.');
        }
    }

    /** Avoid float comparisons, including PayPal's harmless trailing decimal zeros. */
    public static function matches(mixed $value, int $expectedMinor, int $exponent): bool
    {
        if (! is_string($value) || $expectedMinor < 0 || ! in_array($exponent, [0, 2], true)
            || preg_match('/\A(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?\z/D', $value, $m) !== 1) {
            return false;
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $exponent) {
            return false;
        }
        $whole = (int) $m[1];
        if ($whole > intdiv(PHP_INT_MAX, 100)) {
            return false;
        }

        return $whole * (10 ** $exponent) + (int) str_pad($fraction, $exponent, '0') === $expectedMinor;
    }
}
