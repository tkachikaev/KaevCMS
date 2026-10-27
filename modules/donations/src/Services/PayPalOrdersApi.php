<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use KaevCMS\Modules\Donations\Exceptions\PayPalApiException;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;
use Throwable;

/**
 * An isolated Orders v2 HTTP client; intentionally NOT registered as a player provider yet.
 * Every POST has a persisted request ID from PayPalAttempt. No automatic HTTP retries.
 * HTTPS certificate checks enabled; response bodies/tokens are never logged.
 * See https://developer.paypal.com/api/rest/requests/ and /api/rest/authentication/.
 */
final class PayPalOrdersApi
{
    private const MAX_RESPONSE_BYTES = 262144;

    private const API = ['sandbox' => 'https://api-m.sandbox.paypal.com', 'live' => 'https://api-m.paypal.com'];

    public function accessToken(PayPalApiCredentials $credentials, bool $forceRefresh = false): string
    {
        $cacheKey = $credentials->tokenCacheKey();
        $cached = $forceRefresh ? null : Cache::get($cacheKey);
        if (is_string($cached)) {
            try {
                $token = Crypt::decryptString($cached);
                if (self::validToken($token)) {
                    return $token;
                }
            } catch (Throwable) {
                Cache::forget($cacheKey);
            }
        }

        try {
            $response = Http::withBasicAuth($credentials->clientId, $credentials->clientSecret)
                ->acceptJson()->asForm()->timeout(8)->connectTimeout(4)
                ->withOptions(['allow_redirects' => false])
                ->post(self::API[$credentials->environment].'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new PayPalApiException('oauth_unavailable', false);
        }
        if (! $response->successful()) {
            throw new PayPalApiException('oauth_http_'.$response->status(), false);
        }
        $json = $this->decode($response, false);
        $token = $json['access_token'] ?? null;
        $ttl = $json['expires_in'] ?? null;
        if (! is_string($token) || ! self::validToken($token)
            || ! is_int($ttl) || $ttl < 1 || $ttl > 86400) {
            throw new PayPalApiException('oauth_invalid_response', false);
        }
        // The cache backend may be a DB shared with the website: encrypt bearer tokens at rest.
        $cacheTtl = $ttl - min(60, max(1, intdiv($ttl, 10)));
        if ($cacheTtl > 0) {
            Cache::put($cacheKey, Crypt::encryptString($token), $cacheTtl);
        }

        return $token;
    }

    /** Validate all deterministic request errors BEFORE taking a durable SQL claim. */
    public function preflightCreate(PayPalApiCredentials $credentials, PayPalAttempt $attempt, string $returnUrl, string $cancelUrl): void
    {
        $this->assertAttempt($credentials, $attempt);
        self::assertWebUrl($returnUrl);
        self::assertWebUrl($cancelUrl);
        $this->amount($attempt);
        if (! preg_match('/\A[0-9a-f-]{36}\z/iD', (string) $attempt->create_request_id)
            || ! preg_match('/\A[0-9a-f-]{36}\z/iD', (string) $attempt->capture_request_id)
            || ! preg_match('/\AKC-[0-9a-f-]{36}\z/iD', (string) $attempt->invoice_id)) {
            throw new InvalidArgumentException('Invalid PayPal local request identity.');
        }
    }

    /** @return array{id:string,status:string,approval_url:string} */
    public function createOrder(
        PayPalApiCredentials $credentials,
        PayPalAttempt $attempt,
        string $returnUrl,
        string $cancelUrl,
    ): array {
        $this->preflightCreate($credentials, $attempt, $returnUrl, $cancelUrl);
        if ($attempt->status !== 'creating') {
            throw new LogicException('PayPal Create must be claimed first.');
        }
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $attempt->purpose === 'player' ? (string) $attempt->order?->order_uuid : (string) $attempt->invoice_id,
                'invoice_id' => (string) $attempt->invoice_id,
                'custom_id' => (string) $attempt->invoice_id,
                'amount' => [
                    'currency_code' => (string) $attempt->currency_code,
                    'value' => $this->amount($attempt),
                ],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
            ]]],
        ];
        $data = $this->call('POST', $credentials, '/v2/checkout/orders', $payload, (string) $attempt->create_request_id);
        $id = $this->requiredRemoteId($data['id'] ?? null);
        if (! in_array($data['status'] ?? null, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new PayPalApiException('create_unexpected_status', true);
        }
        $url = self::approvalUrl($data, $credentials->environment, $id);

        return ['id' => $id, 'status' => (string) $data['status'], 'approval_url' => $url];
    }

    /** @return array<string,mixed> Fully remote PayPal response; never payment proof on its own. */
    public function showOrder(PayPalApiCredentials $credentials, string $orderId): array
    {
        $id = $this->requiredRemoteId($orderId);
        $data = $this->call('GET', $credentials, '/v2/checkout/orders/'.$id);
        if (($data['id'] ?? null) !== $id || ! is_string($data['status'] ?? null)) {
            throw new PayPalApiException('show_invalid_response', true);
        }

        return $data;
    }

    /** @return array<string,mixed> Remote capture result, NOT authorization to issue Wallet credit. */
    public function captureOrder(PayPalApiCredentials $credentials, PayPalAttempt $attempt): array
    {
        $this->assertAttempt($credentials, $attempt);
        if ($attempt->status !== 'capturing' || $attempt->paypal_order_id === null) {
            throw new LogicException('PayPal Capture must be claimed first.');
        }
        $id = $this->requiredRemoteId($attempt->paypal_order_id);
        $data = $this->call('POST', $credentials, '/v2/checkout/orders/'.$id.'/capture', (object) [], (string) $attempt->capture_request_id);
        if (($data['id'] ?? null) !== $id || ! is_string($data['status'] ?? null)) {
            throw new PayPalApiException('capture_invalid_response', true);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    public function showCapture(PayPalApiCredentials $credentials, string $captureId): array
    {
        $id = $this->requiredRemoteId($captureId);
        $data = $this->call('GET', $credentials, '/v2/payments/captures/'.$id);
        if (($data['id'] ?? null) !== $id || ! is_string($data['status'] ?? null)) {
            throw new PayPalApiException('capture_lookup_invalid', true);
        }

        return $data;
    }

    /**
     * Check a returned HATEOAS URL, never trust arbitrary domains or redirects.
     * @param array<string,mixed> $data
     */
    public static function approvalUrl(array $data, string $environment, string $expectedOrderId): string
    {
        if (! in_array($environment, ['sandbox', 'live'], true) || ! self::validRemoteId($expectedOrderId)) {
            throw new InvalidArgumentException('Invalid PayPal approval context.');
        }
        $links = $data['links'] ?? null;
        if (! is_array($links) || count($links) > 50) {
            throw new PayPalApiException('approval_link_missing', true);
        }
        foreach ($links as $link) {
            if (! is_array($link) || ! in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                continue;
            }
            $url = $link['href'] ?? null;
            if (! is_string($url) || strlen($url) > 2048 || ! self::validApprovalHost($url, $environment, $expectedOrderId)) {
                throw new PayPalApiException('approval_link_invalid', true);
            }

            return $url;
        }
        throw new PayPalApiException('approval_link_missing', true);
    }

    public static function validApprovalHost(string $url, string $environment, string $orderId): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! self::validRemoteId($orderId)
            || ! in_array($environment, ['sandbox', 'live'], true)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== ($environment === 'sandbox' ? 'www.sandbox.paypal.com' : 'www.paypal.com')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($parts['path'] ?? null, ['/checkoutnow', '/checkoutnow/'], true)) {
            return false;
        }
        $queryString = $parts['query'] ?? '';
        if (preg_match_all('/(?:\A|&)token=/', $queryString) !== 1) {
            return false;
        }
        parse_str($queryString, $query);

        return ($query['token'] ?? null) === $orderId && is_string($query['token'] ?? null);
    }

    /** @return array<string,mixed> */
    private function call(string $method, PayPalApiCredentials $credentials, string $path, array|object|null $json = null, ?string $requestId = null): array
    {
        $token = $this->accessToken($credentials);
        if ($requestId !== null && ! preg_match('/\A[0-9a-f-]{36}\z/iD', $requestId)) {
            throw new InvalidArgumentException('Invalid PayPal idempotency key.');
        }
        try {
            $request = Http::withToken($token)->acceptJson()->asJson()
                ->timeout(10)->connectTimeout(4)
                ->withOptions(['allow_redirects' => false]);
            $request = $request->withHeaders(['Prefer' => 'return=representation']);
            if ($requestId !== null) {
                $request = $request->withHeaders(['PayPal-Request-Id' => $requestId]);
            }
            if ($method === 'GET') {
                $response = $request->get(self::API[$credentials->environment].$path);
            } elseif (is_object($json)) {
                $response = $request->withBody('{}', 'application/json')->post(self::API[$credentials->environment].$path);
            } else {
                $response = $request->post(self::API[$credentials->environment].$path, $json ?? []);
            }
        } catch (ConnectionException) {
            throw new PayPalApiException('transport_unknown', $method === 'POST');
        }
        if (! $response->successful()) {
            // 408, 429, 5xx and unexpected redirects can occur after remote processing.
            $status = $response->status();
            throw new PayPalApiException('http_'.$status, $method === 'POST' && ($status >= 500 || in_array($status, [301, 302, 307, 308, 408, 409, 425, 429], true)));
        }

        return $this->decode($response, $method === 'POST');
    }

    /** @return array<string,mixed> */
    private function decode(Response $response, bool $uncertain): array
    {
        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES || $response->body() === '') {
            throw new PayPalApiException('response_size', $uncertain);
        }
        try {
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new PayPalApiException('response_json', $uncertain);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new PayPalApiException('response_structure', $uncertain);
        }

        return $data;
    }

    public function assertAttempt(PayPalApiCredentials $credentials, PayPalAttempt $attempt): void
    {
        if ($attempt->environment !== $credentials->environment
            || $attempt->merchant_identity !== $credentials->merchantIdentity()
            || ! in_array($attempt->purpose, ['player', 'admin_test'], true)
            || ($attempt->purpose === 'player' && ($attempt->order_id === null || $attempt->preparation_id === null))
            || ($attempt->purpose === 'admin_test' && ($attempt->order_id !== null || $attempt->preparation_id !== null))) {
            throw new LogicException('PayPal attempt does not belong to these credentials.');
        }
    }

    private function amount(PayPalAttempt $attempt): string
    {
        if ((int) $attempt->gross_amount_minor < 1) {
            throw new InvalidArgumentException('Unsupported PayPal money snapshot.');
        }
        PayPalMoney::assertExponent((string) $attempt->currency_code, (int) $attempt->exponent);

        return (new DonationMoney)->format((int) $attempt->gross_amount_minor, (int) $attempt->exponent);
    }

    private static function assertWebUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strlen($url) > 2048 || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid PayPal return/cancel URL.');
        }
    }

    private static function validToken(string $token): bool
    {
        return $token !== '' && strlen($token) <= 4096 && preg_match('/\A[A-Za-z0-9._~+\/-]+\z/D', $token) === 1;
    }

    private function requiredRemoteId(mixed $id): string
    {
        if (! is_string($id) || ! self::validRemoteId($id)) {
            throw new PayPalApiException('invalid_remote_id', true);
        }

        return $id;
    }

    private static function validRemoteId(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $id) === 1;
    }
}
