<?php

namespace KaevCMS\Modules\Donations\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use RuntimeException;

/** Postback verification: put the original raw JSON object in webhook_event unchanged. */
final class PayPalWebhookVerifier
{
    public function __construct(private readonly PayPalOrdersApi $api) {}

    public function verified(string $rawBody, array $headers, string $webhookId, PayPalApiCredentials $credentials): bool
    {
        if ($rawBody === '' || strlen($rawBody) > 65536 || ! self::isJsonObject($rawBody)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $webhookId)) {
            throw new InvalidArgumentException('Invalid PayPal webhook.');
        }
        $names = ['transmission_id' => 'paypal-transmission-id', 'transmission_time' => 'paypal-transmission-time',
            'cert_url' => 'paypal-cert-url', 'auth_algo' => 'paypal-auth-algo',
            'transmission_sig' => 'paypal-transmission-sig'];
        $fields = [];
        foreach ($names as $field => $header) {
            $value = $headers[$header] ?? null;
            if (! is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Missing PayPal signature header.');
            }
            $fields[$field] = $value;
        }
        if (! in_array($fields['auth_algo'], ['SHA256withRSA'], true)
            || ! filter_var($fields['cert_url'], FILTER_VALIDATE_URL)
            || ! str_starts_with($fields['cert_url'], 'https://')
            || ! preg_match('/\A[A-Za-z0-9+\/=]{10,2048}\z/D', $fields['transmission_sig'])) {
            throw new InvalidArgumentException('Invalid PayPal signature metadata.');
        }
        $fields['webhook_id'] = $webhookId;
        // No json_decode -> json_encode round trip for the webhook event.
        $prefix = json_encode($fields, JSON_THROW_ON_ERROR);
        $body = substr($prefix, 0, -1).',"webhook_event":'.$rawBody.'}';
        $host = $credentials->environment === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        try {
            $response = Http::withToken($this->api->accessToken($credentials))->acceptJson()
                ->withBody($body, 'application/json')->timeout(10)->connectTimeout(4)
                ->withOptions(['allow_redirects' => false])
                ->post($host.'/v1/notifications/verify-webhook-signature');
        } catch (ConnectionException) {
            throw new RuntimeException('PayPal webhook verification unavailable.');
        }
        if (! $response->successful() || strlen($response->body()) > 4096) {
            throw new RuntimeException('PayPal webhook verification unavailable.');
        }
        $data = json_decode($response->body(), true);
        if (! is_array($data) || ! in_array($data['verification_status'] ?? null, ['SUCCESS', 'FAILURE'], true)) {
            throw new RuntimeException('Invalid PayPal signature verification response.');
        }
        return $data['verification_status'] === 'SUCCESS';
    }

    public static function isJsonObject(string $raw): bool
    {
        try {
            $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            return is_object($value);
        } catch (\JsonException) {
            return false;
        }
    }
}
