<?php

namespace KaevCMS\Modules\Donations\Providers;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\PaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Services\DonationMoney;

/**
 * YooMoney wallet P2P collection, NOT YooKassa. The form can only render through the explicit C5 trusted checkout gates;
 * provider callback/Wallet credit gates remain disabled by default.
 * https://yoomoney.ru/docs/payment-buttons/using-api/notifications
 */
final class YooMoneyProvider implements PaymentProvider
{
    public const CHECKOUT_URL = 'https://yoomoney.ru/quickpay/confirm';

    public const HISTORY_URL = 'https://yoomoney.ru/api/operation-history';

    public function code(): string
    {
        return 'yoomoney';
    }

    public function name(): string
    {
        return 'ЮMoney (кошелёк / wallet)';
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
            'receiver' => ['label' => 'Номер кошелька ЮMoney / Wallet number', 'secret' => false, 'required' => true],
            'notification_secret' => ['label' => 'HTTP notification HMAC-SHA256 secret', 'secret' => true, 'required' => true],
            'history_token' => ['label' => 'Read-only OAuth history token (optional)', 'secret' => true, 'required' => false],
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
            throw new InvalidArgumentException('Invalid YooMoney signature.');
        }
        unset($params['sign']);
        ksort($params, SORT_STRING);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key.'='.rawurlencode($value);
        }
        $expected = hash_hmac('sha256', implode('&', $parts), $credentials['notification_secret']);
        if (! hash_equals($expected, strtolower($signature))) {
            throw new InvalidArgumentException('Invalid YooMoney signature.');
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
