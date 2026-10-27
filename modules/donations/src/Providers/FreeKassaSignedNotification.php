<?php

namespace KaevCMS\Modules\Donations\Providers;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Exceptions\InvalidPaymentSignature;

/**
 * SCI callback parser. Only MERCHANT_ID, AMOUNT and MERCHANT_ORDER_ID are
 * covered by FreeKassa's MD5 signature. CUR_ID is a payment METHOD, not a
 * signed settlement currency. commission and payer data are NOT trusted.
 * Source: https://docs.freekassa.net/ (SCI 1.4, 1.7).
 */
final readonly class FreeKassaSignedNotification
{
    public function __construct(
        public string $orderUuid,
        public string $operationId,
        public int $amountMinor,
        public string $rawAmount,
    ) {}

    public static function referenceHint(ProviderCallback $callback): string
    {
        $fields = self::fields($callback);
        $reference = $fields['MERCHANT_ORDER_ID'] ?? '';
        if (! self::isUuid($reference)) {
            throw new InvalidArgumentException('Invalid FreeKassa payment reference.');
        }

        return $reference;
    }

    public static function verify(ProviderCallback $callback, string $merchantId, string $secret): self
    {
        $fields = self::fields($callback);
        $reference = $fields['MERCHANT_ORDER_ID'] ?? '';
        $shop = $fields['MERCHANT_ID'] ?? '';
        $amount = $fields['AMOUNT'] ?? '';
        $signature = $fields['SIGN'] ?? '';
        $operationId = $fields['intid'] ?? '';
        $paymentMethod = $fields['CUR_ID'] ?? '';
        if (! self::isUuid($reference)
            || ! preg_match('/\A[1-9][0-9]{0,19}\z/D', $shop)
            || ! hash_equals($merchantId, $shop)
            || ! preg_match('/\A[1-9][0-9]{0,63}\z/D', $operationId)
            || ! preg_match('/\A[1-9][0-9]{0,9}\z/D', $paymentMethod)) {
            throw new InvalidArgumentException('Invalid FreeKassa payment notification.');
        }
        $minor = self::parseAmountMinor($amount);
        if (! preg_match('/\A[a-fA-F0-9]{32}\z/D', $signature)) {
            throw new InvalidPaymentSignature('Invalid FreeKassa signature.');
        }
        // Use the ORIGINAL amount string for the signature, then compare money
        // as integer minor units (100 == 100.0 == 100.00).
        $expected = md5($shop.':'.$amount.':'.$secret.':'.$reference);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new InvalidPaymentSignature('Invalid FreeKassa signature.');
        }

        return new self($reference, $operationId, $minor, $amount);
    }

    public static function parseAmountMinor(string $amount): int
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D', $amount)) {
            throw new InvalidArgumentException('Invalid FreeKassa amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minor < 1) {
            throw new InvalidArgumentException('Invalid FreeKassa amount.');
        }

        return $minor;
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value);
    }

    /** @return array<string,string> */
    private static function fields(ProviderCallback $callback): array
    {
        if ($callback->rawBody === '' || strlen($callback->rawBody) > 16384
            || $callback->queryString !== '') {
            throw new InvalidArgumentException('Invalid FreeKassa notification transport.');
        }
        $fields = [];
        foreach (explode('&', $callback->rawBody) as $item) {
            $parts = explode('=', $item, 2);
            $key = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            if (! preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D', $key)
                || array_key_exists($key, $fields) || strlen($value) > 2048) {
                throw new InvalidArgumentException('Invalid FreeKassa notification fields.');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }
}
