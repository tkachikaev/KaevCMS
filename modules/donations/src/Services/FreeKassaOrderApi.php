<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Merchant-authenticated FreeKassa orders API verification. Do not treat the
 * signed SCI callback alone as currency/status proof: CUR_ID is only a method.
 * Official API contract: https://docs.freekassa.net/ (POST /v1/orders).
 */
final class FreeKassaOrderApi
{
    private const API_URL = 'https://api.fk.life/v1/orders';

    public function __construct(private readonly DonationProviderSettings $settings) {}

    public function assertPaid(
        string $merchantId,
        string $apiKey,
        string $orderUuid,
        ?string $operationId,
        int $amountMinor,
        string $currency,
    ): string {
        if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', $merchantId)
            || ($operationId !== null && ! preg_match('/\A[1-9][0-9]{0,63}\z/D', $operationId))
            || ! preg_match('/\A[0-9a-f-]{36}\z/D', $orderUuid)
            || ! preg_match('/\A[A-Z]{3}\z/D', $currency)
            || $apiKey === '' || $amountMinor < 1) {
            throw new InvalidArgumentException('Invalid FreeKassa API proof parameters.');
        }
        $payload = [
            'shopId' => (int) $merchantId,
            'nonce' => $this->settings->nextFreeKassaApiNonce($merchantId),
            'paymentId' => $orderUuid,
        ];
        ksort($payload, SORT_STRING);
        $payload['signature'] = hash_hmac('sha256', implode('|', $payload), $apiKey);
        // HTTPS/certificate verification stays enabled; secrets never enter logs.
        $response = Http::acceptJson()->asJson()->timeout(8)->connectTimeout(4)
            ->withOptions(['allow_redirects' => false])->post(self::API_URL, $payload);
        if (! $response->successful() || strlen($response->body()) > 131072) {
            throw new RuntimeException('Unable to verify FreeKassa order by API.');
        }
        // intid is NOT covered by SCI signature. Canonical financial operation
        // identity comes from authenticated merchant API, never callback intid.
        return self::paidOrderIdFromResponse($response->body(), $orderUuid, $amountMinor, $currency);
    }

    /**
     * Pure parser for signed merchant API query result. Money stays decimal text:
     * JSON numeric amount tokens are quoted before json_decode to avoid float.
     * A mismatch or unavailable API is never permission to credit Wallet.
     */
    public static function paidOrderIdFromResponse(
        string $json,
        string $orderUuid,
        int $amountMinor,
        string $currency,
    ): string {
        if ($json === '' || strlen($json) > 131072) {
            throw new RuntimeException('Invalid FreeKassa API response.');
        }
        // Only the "amount" field is converted; preserve its numeric token verbatim.
        // JSON is parsed *afterwards* to validate structure and field types.
        $protected = preg_replace_callback(
            '/("amount"\s*:\s*)(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)(?=\s*[,}])/D',
            static fn (array $matches): string => $matches[1].'"'.$matches[2].'"', $json,
        );
        if (! is_string($protected)) {
            throw new RuntimeException('Invalid FreeKassa API response.');
        }
        try {
            $payload = json_decode($protected, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new RuntimeException('Invalid FreeKassa API JSON.');
        }
        if (! is_array($payload) || ($payload['type'] ?? null) !== 'success'
            || ! is_array($payload['orders'] ?? null) || ! array_is_list($payload['orders'])
            || count($payload['orders']) > 100
            || (isset($payload['pages']) && (! is_int($payload['pages']) || $payload['pages'] !== 1))) {
            throw new RuntimeException('FreeKassa did not confirm the paid order.');
        }
        $matched = 0;
        $canonicalId = null;
        foreach ($payload['orders'] as $order) {
            if (! is_array($order)) {
                throw new RuntimeException('Malformed FreeKassa order row.');
            }
            // Query was scoped to our immutable UUID. An unrelated row is
            // an invalid/ambiguous response, not permission to credit.
            if (($order['merchant_order_id'] ?? null) !== $orderUuid) {
                throw new RuntimeException('FreeKassa API returned an unrelated order.');
            }
            $candidateId = $order['fk_order_id'] ?? null;
            if (! self::validIntegerId($candidateId)) {
                throw new RuntimeException('FreeKassa API order ID missing.');
            }
            $canonicalId = (string) $candidateId;
            $matched++;
            if ($matched > 1 || ($order['status'] ?? null) !== 1
                || ($order['currency'] ?? null) !== $currency
                || ! is_string($order['amount'] ?? null)) {
                throw new RuntimeException('FreeKassa order status, currency or amount mismatch.');
            }
            try {
                $apiMinor = \KaevCMS\Modules\Donations\Providers\FreeKassaSignedNotification::parseAmountMinor($order['amount']);
            } catch (InvalidArgumentException) {
                throw new RuntimeException('Invalid FreeKassa API amount.');
            }
            if ($apiMinor !== $amountMinor) {
                throw new RuntimeException('FreeKassa paid amount mismatch.');
            }
        }
        if ($matched !== 1 || $canonicalId === null) {
            throw new RuntimeException('FreeKassa operation not confirmed by API.');
        }

        return $canonicalId;
    }

    private static function validIntegerId(mixed $candidate): bool
    {
        if ((! is_string($candidate) && ! is_int($candidate))
            || ! preg_match('/\A[1-9][0-9]{0,63}\z/D', (string) $candidate)) {
            return false;
        }

        return true;
    }
}
