<?php

namespace KaevCMS\Modules\Donations\Data;

use InvalidArgumentException;

/**
 * Only wrap a notification AFTER the provider has verified its HMAC signature.
 * Laravel Crypt then authenticates this immutable evidence for deferred credits:
 * HMAC-key rotation must not invalidate a callback we already verified and
 * committed with the receipt and audit. This is NOT for unsigned history scans.
 */
final class VerifiedReceiptEnvelope
{
    private const FORMAT = 'kaevcms-signed-yoomoney-v1';

    public static function fingerprint(VerifiedPayment $payment): string
    {
        return hash('sha256', json_encode([
            $payment->orderUuid, $payment->providerPaymentId, $payment->status,
            $payment->currencyCode, $payment->amountMinor, $payment->receivedAmountMinor,
            $payment->testNotification, $payment->environment,
        ], JSON_THROW_ON_ERROR));
    }

    public static function seal(VerifiedPayment $payment, string $rawBody): string
    {
        if ($rawBody === '' || strlen($rawBody) > 16384) {
            throw new InvalidArgumentException('Invalid notification evidence.');
        }

        return json_encode([
            '_format' => self::FORMAT,
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
     * @return array{verified:VerifiedPayment,rawBody:string}|null
     * Returns null for unmodified legacy receipts (raw URL-encoded body).
     * Caller MUST decrypt using Laravel Crypt before invoking this method.
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
        if (! is_array($payload) || ($payload['_format'] ?? null) !== self::FORMAT
            || ! is_string($payload['rawBody'] ?? null)
            || $payload['rawBody'] === '' || strlen($payload['rawBody']) > 16384
            || ! is_array($payload['payment'] ?? null)) {
            throw new InvalidArgumentException('Invalid stored notification evidence.');
        }
        $value = $payload['payment'];
        if (! is_string($value['orderUuid'] ?? null)
            || ! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value['orderUuid'])
            || ! is_string($value['providerPaymentId'] ?? null)
            || ! preg_match('/\A[0-9]{1,64}\z/D', $value['providerPaymentId'])
            || ($value['status'] ?? null) !== 'received_unsettled'
            || ($value['currencyCode'] ?? null) !== 'RUB'
            || ! is_int($value['amountMinor'] ?? null) || $value['amountMinor'] < 1
            || ! is_int($value['receivedAmountMinor'] ?? null) || $value['receivedAmountMinor'] < 1
            || $value['receivedAmountMinor'] > $value['amountMinor']
            || ! is_bool($value['testNotification'] ?? null)
            || ! in_array($value['environment'] ?? null, ['test', 'live'], true)) {
            throw new InvalidArgumentException('Invalid stored notification evidence.');
        }

        return [
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
