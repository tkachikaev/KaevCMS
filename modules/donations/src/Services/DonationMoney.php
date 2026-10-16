<?php

namespace KaevCMS\Modules\Donations\Services;

use InvalidArgumentException;

/** Exact integer minor-unit arithmetic; PHP floats are never used for monetary values. */
final class DonationMoney
{
    public const MAX_UNIT_PRICE_MINOR = 999999999;
    public const MAX_COINS = 1000000;

    public function parseUnitPrice(string $value, int $exponent): int
    {
        if ($exponent < 0 || $exponent > 4 || ! preg_match('/\A[0-9]{1,12}(?:[.,][0-9]{1,4})?\z/D', $value)) {
            throw new InvalidArgumentException('Invalid monetary unit price.');
        }
        $parts = explode('.', str_replace(',', '.', $value), 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException('Price exceeds the currency precision.');
        }
        // String-length bound before integer conversion prevents overflow on 32/64-bit runtimes.
        if (strlen($whole) > 9) {
            throw new InvalidArgumentException('Price exceeds the limit.');
        }
        $minor = (int) $whole * (10 ** $exponent) + (int) str_pad($fraction, $exponent, '0');
        if ($minor < 1 || $minor > self::MAX_UNIT_PRICE_MINOR) {
            throw new InvalidArgumentException('Unit price must be positive and within the limit.');
        }
        return $minor;
    }

    public function totalMinor(int $unitPriceMinor, int $coins): int
    {
        if ($unitPriceMinor < 1 || $unitPriceMinor > self::MAX_UNIT_PRICE_MINOR || $coins < 1 || $coins > self::MAX_COINS) {
            throw new InvalidArgumentException('Invalid price or number of coins.');
        }
        // With these bounds the result is < 10^15 and therefore safe on PHP 64-bit.
        return $unitPriceMinor * $coins;
    }

    public function format(int $minor, int $exponent): string
    {
        if ($minor < 0 || $exponent < 0 || $exponent > 4) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        $factor = 10 ** $exponent;
        $whole = intdiv($minor, $factor);
        $fraction = $minor % $factor;
        return (string) $whole.($exponent === 0 ? '' : '.'.str_pad((string) $fraction, $exponent, '0', STR_PAD_LEFT));
    }
}
