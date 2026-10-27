<?php

namespace KaevCMS\Modules\Donations\Providers;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Exceptions\InvalidPaymentSignature;
use KaevCMS\Modules\Donations\Services\DonationMoney;

/**
 * YooMoney wallet P2P collection, NOT YooKassa. Checkout requires trusted static
 * guards and audited database activation, which defaults off. Signed callbacks
 * and in-flight Wallet settlement remain available when new sales are closed.
 * https://yoomoney.ru/docs/payment-buttons/using-api/notifications
 */
final class YooMoneyProvider implements RoutablePaymentProvider
{
    public const CHECKOUT_URL = 'https://yoomoney.ru/quickpay/confirm';

    public const HISTORY_URL = 'https://yoomoney.ru/api/operation-history';

    public function code(): string
    {
        return 'yoomoney';
    }

    public function name(): string
    {
        return 'ЮMoney';
    }

    public function supportedCurrencies(): array
    {
        return ['RUB'];
    }

    public function supportsTestMode(): bool
    {
        // Local simulations and provider test notifications; NOT a YooMoney payment sandbox.
        return true;
    }

    public function credentialFields(): array
    {
        return [
            'receiver' => ['label' => 'Номер кошелька ЮMoney', 'secret' => false, 'required' => true],
            'notification_secret' => ['label' => 'Секрет HTTP-уведомлений', 'secret' => true, 'required' => true],
            'history_token' => ['label' => 'Токен истории операций (необязательно)', 'secret' => true, 'required' => false],
        ];
    }

    /** @param array<string,mixed> $credentials */
    public function assertConfiguration(array $credentials, string $mode): void
    {
        if (! in_array($mode, ['test', 'live'], true)
            || ! is_string($credentials['receiver'] ?? null)
            || ! preg_match('/\A4100[0-9]{9,20}\z/D', $credentials['receiver'])
            || ! is_string($credentials['notification_secret'] ?? null)
            || strlen($credentials['notification_secret']) < 8
            || strlen($credentials['notification_secret']) > 1024
            || (isset($credentials['history_token']) && (! is_string($credentials['history_token'])
                || strlen($credentials['history_token']) > 1024))) {
            throw new InvalidArgumentException('Invalid YooMoney configuration.');
        }
    }

    /** @param array<string,string> $credentials */
    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        $this->assertConfiguration($credentials, $mode);
        if ($mode !== 'live' || $intent->currencyCode !== 'RUB' || $intent->currencyExponent !== 2
            || $intent->coins < 1 || $intent->amountMinor < 1
            || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $intent->orderUuid)) {
            throw new InvalidArgumentException('Invalid YooMoney payment intent.');
        }

        // sum means PAYER DEBIT, not the net receiver amount. Owner bears provider fees.
        // No external request is made here. Only the trusted checkout controller can
        // render the returned fields, after all four payment gates pass.
        return new PaymentCheckout(
            providerPaymentId: null, // YooMoney allocates operation_id only after transfer.
            redirectUrl: self::CHECKOUT_URL,
            method: 'POST',
            fields: [
                'receiver' => $credentials['receiver'],
                'quickpay-form' => 'button',
                'paymentType' => 'PC',
                'sum' => (new DonationMoney)->format($intent->amountMinor, 2),
                'label' => self::label($intent->orderUuid),
                'successURL' => $intent->returnUrl,
            ],
        );
    }

    public static function label(string $orderUuid): string
    {
        return 'kaevcms-'.strtolower($orderUuid);
    }

    public function referenceForOrder(string $orderUuid): string
    {
        return self::label($orderUuid);
    }

    public function referenceFromCallback(ProviderCallback $callback): string
    {
        // This is ONLY a hint to find a preparation; verifyCallback() rejects
        // duplicate/array parameters and verifies the full signed payload.
        parse_str($callback->rawBody, $hint);
        $label = $hint['label'] ?? null;
        if (! is_string($label) || ! preg_match('/\Akaevcms-[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $label)) {
            throw new InvalidArgumentException('Invalid payment reference.');
        }

        return $label;
    }

    public function callbackRouteName(): string
    {
        return 'payments.yoomoney.notify';
    }

    /** @param array<string,string> $credentials */
    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void
    {
        if ($checkout->redirectUrl !== self::CHECKOUT_URL || $checkout->method !== 'POST'
            || ($checkout->fields['receiver'] ?? null) !== ($credentials['receiver'] ?? null)
            || ($checkout->fields['label'] ?? null) !== $reference
            || ($checkout->fields['sum'] ?? null) !== (new DonationMoney)->format($intent->amountMinor, 2)
            || ($checkout->fields['quickpay-form'] ?? null) !== 'button'
            || ($checkout->fields['paymentType'] ?? null) !== 'PC'
            || ($checkout->fields['successURL'] ?? null) !== $intent->returnUrl) {
            throw new InvalidArgumentException('Invalid trusted YooMoney checkout terms.');
        }
    }

    /** @param array<string,string> $credentials */
    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        $this->assertConfiguration($credentials, $mode);
        if (strlen($callback->rawBody) > 16384 || $callback->queryString !== '') {
            throw new InvalidArgumentException('Invalid YooMoney notification.');
        }
        // Keep original decimal strings and detect duplicate or array parameters: parse_str()
        // would otherwise overwrite values silently. Only signed form body may be verified.
        $params = [];
        foreach (explode('&', $callback->rawBody) as $part) {
            $pair = explode('=', $part, 2);
            $key = urldecode($pair[0]);
            if (! preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) || array_key_exists($key, $params)) {
                throw new InvalidArgumentException('Invalid YooMoney notification.');
            }
            $params[$key] = urldecode($pair[1] ?? '');
        }
        $signature = $params['sign'] ?? '';
        if (! preg_match('/\A[a-fA-F0-9]{64}\z/D', $signature)) {
            throw new InvalidPaymentSignature('Invalid YooMoney signature.');
        }
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key.'='.rawurlencode($value);
        }
        $expected = hash_hmac('sha256', implode('&', $parts), $credentials['notification_secret']);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new InvalidPaymentSignature('Invalid YooMoney signature.');
        }
        if (! in_array($params['notification_type'] ?? null, ['p2p-incoming', 'card-incoming'], true)
            || ($params['currency'] ?? null) !== '643'
            || ($params['codepro'] ?? null) !== 'false'
            || ($params['unaccepted'] ?? null) !== 'false'
            || ! preg_match('/\A[0-9]{1,64}\z/D', $params['operation_id'] ?? '')
            || ! preg_match('/\Akaevcms-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/D', $params['label'] ?? '', $match)
            || ! in_array($params['test_notification'] ?? 'false', ['true', 'false'], true)) {
            throw new InvalidArgumentException('Invalid YooMoney notification.');
        }
        $net = $this->parseRubMinor($params['amount'] ?? '');
        $gross = $this->parseRubMinor($params['withdraw_amount'] ?? '');
        if ($net < 1 || $gross < $net) {
            throw new InvalidArgumentException('Invalid YooMoney notification amounts.');
        }

        // 'verified' is NOT 'settled': C3 must match order snapshot, operation ID,
        // fee policy, mode, replay history and API reconciliation before wallet credit.
        return new VerifiedPayment(
            orderUuid: $match[1], providerPaymentId: $params['operation_id'],
            status: 'received_unsettled', currencyCode: 'RUB', amountMinor: $gross,
            receivedAmountMinor: $net, testNotification: ($params['test_notification'] ?? '') === 'true',
            environment: $mode,
        );
    }

    /**
     * Signature check for the "Test" notification an owner sends from YooMoney's HTTP
     * notification settings. It never credits anything and is separate from
     * verifyCallback(), which alone handles real payments. Both the HMAC-SHA256 "sign"
     * used by verifyCallback() and YooMoney's documented sha1_hash are accepted.
     */
    public function testNotificationSignatureValid(string $rawBody, string $secret): bool
    {
        if ($rawBody === '' || strlen($rawBody) > 16384 || $secret === '') {
            return false;
        }
        $params = [];
        foreach (explode('&', $rawBody) as $part) {
            $pair = explode('=', $part, 2);
            $key = urldecode($pair[0]);
            if (! preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) || array_key_exists($key, $params)) {
                return false;
            }
            $params[$key] = urldecode($pair[1] ?? '');
        }
        if (($params['test_notification'] ?? null) !== 'true') {
            return false;
        }

        $signature = strtolower($params['sign'] ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $signature)) {
            $signed = $params;
            unset($signed['sign']);
            ksort($signed, SORT_STRING);
            $parts = [];
            foreach ($signed as $key => $value) {
                $parts[] = $key.'='.rawurlencode($value);
            }

            return hash_equals(hash_hmac('sha256', implode('&', $parts), $secret), $signature);
        }

        $sha1 = strtolower($params['sha1_hash'] ?? '');
        if (preg_match('/\A[a-f0-9]{40}\z/D', $sha1)) {
            $source = implode('&', [
                $params['notification_type'] ?? '', $params['operation_id'] ?? '', $params['amount'] ?? '',
                $params['currency'] ?? '', $params['datetime'] ?? '', $params['sender'] ?? '',
                $params['codepro'] ?? '', $secret, $params['label'] ?? '',
            ]);

            return hash_equals(sha1($source), $sha1);
        }

        return false;
    }

    /** Strict exact decimal parser: no float, no exponent, no round-off. */
    private function parseRubMinor(string $amount): int
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D', $amount)) {
            throw new InvalidArgumentException('Invalid YooMoney amount.');
        }
        $pieces = explode('.', $amount, 2);

        return (int) $pieces[0] * 100 + (int) str_pad($pieces[1] ?? '', 2, '0');
    }
}
