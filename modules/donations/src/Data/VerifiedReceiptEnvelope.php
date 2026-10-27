<?php

namespace KaevCMS\Modules\Donations\Data;

use InvalidArgumentException;

/**
 * Only wrap a payment after trusted verification: signed provider notification
 * (with merchant API confirmation where required), or an authenticated
 * merchant API reconciliation of the exact previously prepared order.
 * Laravel Crypt then authenticates this immutable evidence for deferred credits:
 * Secret rotation must not invalidate a payment we already verified and
 * committed with the receipt and audit. This is NOT for unsigned history scans.
 */
final class VerifiedReceiptEnvelope
{
    private const LEGACY_FORMAT = 'kaevcms-signed-yoomoney-v1';

    private const FORMAT = 'kaevcms-signed-provider-v2';

    public static function fingerprint(VerifiedPayment $payment): string
    {
        return hash('sha256', json_encode([
            $payment->orderUuid, $payment->providerPaymentId, $payment->status,
            $payment->currencyCode, $payment->amountMinor, $payment->receivedAmountMinor,
            $payment->testNotification, $payment->environment,
        ], JSON_THROW_ON_ERROR));
    }

    public static function seal(VerifiedPayment $payment, string $rawBody, string $providerCode = 'yoomoney'): string
    {
        if ($rawBody === '' || strlen($rawBody) > 16384) {
            throw new InvalidArgumentException('Invalid notification evidence.');
        }

        if (! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $providerCode)) {
            throw new InvalidArgumentException('Invalid evidence provider.');
        }

        return json_encode([
            // Keep historical YooMoney v1 writes byte-compatible. Other reviewed
            // drivers produce v2 with an authenticated provider identity.
            '_format' => $providerCode === 'yoomoney' ? self::LEGACY_FORMAT : self::FORMAT,
            ...($providerCode === 'yoomoney' ? [] : ['providerCode' => $providerCode]),
            'rawBody' => $rawBody,
            'payment' => [
                'orderUuid' => $payment->orderUuid,
                'providerPaymentId' => $payment->providerPaymentId,
                'status' => $payment->status,
                'currencyCode' => $payment->currencyCode,
                'amountMinor' => $payment->amountMinor,
                'receivedAmountMinor' => $payment->receivedAmountMinor,
                'testNotification' => $payment->testNotification,
                'environment' => $payment->environment,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{verified:VerifiedPayment,rawBody:string,providerCode:string}|null
     *                                                             Returns null for unmodified legacy receipts (raw URL-encoded body).
     *                                                             Caller MUST decrypt using Laravel Crypt before invoking this method.
     */
    public static function restore(string $plaintext): ?array
    {
        if (! str_starts_with($plaintext, '{')) {
            return null;
        }
        try {
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid stored notification evidence.');
        }
        $format = $payload['_format'] ?? null;
        if (! is_array($payload) || ! in_array($format, [self::LEGACY_FORMAT, self::FORMAT], true)
            || ! is_string($payload['rawBody'] ?? null)
            || $payload['rawBody'] === '' || strlen($payload['rawBody']) > 16384
            || ! is_array($payload['payment'] ?? null)) {
            throw new InvalidArgumentException('Invalid stored notification evidence.');
        }
        $providerCode = $format === self::LEGACY_FORMAT ? 'yoomoney' : ($payload['providerCode'] ?? null);
        $value = $payload['payment'];
        if (! is_string($providerCode) || ! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $providerCode)
            || ! is_string($value['orderUuid'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value['orderUuid'])
            || ! is_string($value['providerPaymentId'] ?? null)
            || ! preg_match($format === self::LEGACY_FORMAT ? '/\A[0-9]{1,64}\z/D' : '/\A[a-zA-Z0-9_-]{1,190}\z/D', $value['providerPaymentId'])
            || ($value['status'] ?? null) !== 'received_unsettled'
            || ($format === self::LEGACY_FORMAT
                ? ($value['currencyCode'] ?? null) !== 'RUB'
                : (! is_string($value['currencyCode'] ?? null)
                    || ! preg_match('/\A[A-Z]{3}\z/D', $value['currencyCode'])))
            || ! is_int($value['amountMinor'] ?? null) || $value['amountMinor'] < 1
            || ! is_int($value['receivedAmountMinor'] ?? null) || $value['receivedAmountMinor'] < 1
            || $value['receivedAmountMinor'] > $value['amountMinor']
            || ! is_bool($value['testNotification'] ?? null)
            || ! in_array($value['environment'] ?? null, ['test', 'live'], true)) {
            throw new InvalidArgumentException('Invalid stored notification evidence.');
        }

        return [
            'providerCode' => $providerCode,
            'rawBody' => $payload['rawBody'],
            'verified' => new VerifiedPayment(
                orderUuid: $value['orderUuid'],
                providerPaymentId: $value['providerPaymentId'],
                status: $value['status'],
                currencyCode: $value['currencyCode'],
                amountMinor: $value['amountMinor'],
                receivedAmountMinor: $value['receivedAmountMinor'],
                testNotification: $value['testNotification'],
                environment: $value['environment'],
            ),
        ];
    }
}
