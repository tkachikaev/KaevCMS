<?php

namespace KaevCMS\Modules\Donations\Providers;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Data\VerifiedPayment;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationMoney;

/**
 * SCI driver, iteration 2. LIVE payments are intentionally unavailable until
 * merchant-side test checkout, trusted-proxy/IP policy and API confirmation of
 * currency/status are implemented in subsequent iterations.
 * https://docs.freekassa.net/
 */
final class FreeKassaProvider implements RoutablePaymentProvider
{
    public const CHECKOUT_URL = 'https://pay.fk.money/';

    public function code(): string
    {
        return 'freekassa';
    }

    public function name(): string
    {
        return 'FreeKassa';
    }

    public function supportedCurrencies(): array
    {
        return ['RUB', 'USD', 'EUR', 'UAH', 'KZT'];
    }

    public function supportsTestMode(): bool
    {
        return true;
    }

    public function credentialFields(): array
    {
        return [
            'merchant_id' => ['label' => 'ID магазина FreeKassa', 'secret' => false, 'required' => true],
            'secret_1' => ['label' => 'Секретное слово 1 (форма оплаты)', 'secret' => true, 'required' => true],
            'secret_2' => ['label' => 'Секретное слово 2 (уведомления)', 'secret' => true, 'required' => true],
            // Used by future server-side currency/status reconciliation; not required in iteration 2.
            'api_key' => ['label' => 'API-ключ (для проверки заказов)', 'secret' => true, 'required' => false],
        ];
    }

    /** @param array<string,string> $credentials */
    public function assertConfiguration(array $credentials, string $mode): void
    {
        if (! in_array($mode, ['test', 'live'], true)
            || ! preg_match('/\A[1-9][0-9]{0,19}\z/D', $credentials['merchant_id'] ?? '')
            || ! $this->validSecret($credentials['secret_1'] ?? null)
            || ! $this->validSecret($credentials['secret_2'] ?? null)
            || (array_key_exists('api_key', $credentials)
                && $credentials['api_key'] !== '' && ! $this->validSecret($credentials['api_key']))) {
            throw new InvalidArgumentException('Invalid FreeKassa configuration.');
        }
        // Deliberate fail-closed release boundary. No real purchase or credit
        // becomes possible just because a merchant enters credentials early.
        if ($mode === 'live') {
            throw new InvalidArgumentException('FreeKassa live payments are not activated in this iteration.');
        }
    }

    /** @param array<string,string> $credentials */
    public function beginPayment(PaymentIntent $intent, array $credentials, string $mode): PaymentCheckout
    {
        $this->assertConfiguration($credentials, $mode);
        if ($intent->coins < 1 || $intent->amountMinor < 1 || $intent->currencyExponent !== 2
            || ! in_array($intent->currencyCode, $this->supportedCurrencies(), true)
            || $this->referenceForOrder($intent->orderUuid) !== $intent->orderUuid) {
            throw new InvalidArgumentException('Invalid FreeKassa payment intent.');
        }
        // Merchant's *external* test setting must also be enabled before this
        // checkout can be used. CMS test preparations never execute checkout yet.
        $amount = (new DonationMoney)->format($intent->amountMinor, 2);
        $reference = $this->referenceForOrder($intent->orderUuid);
        $fields = [
            'm' => $credentials['merchant_id'],
            'oa' => $amount,
            'currency' => $intent->currencyCode,
            'o' => $reference,
            's' => md5($credentials['merchant_id'].':'.$amount.':'.$credentials['secret_1'].':'.$intent->currencyCode.':'.$reference),
        ];

        return new PaymentCheckout(null, self::CHECKOUT_URL, 'GET', $fields);
    }

    public function referenceForOrder(string $orderUuid): string
    {
        if (! preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderUuid)) {
            throw new InvalidArgumentException('Invalid FreeKassa order reference.');
        }

        return $orderUuid;
    }

    public function referenceFromCallback(ProviderCallback $callback): string
    {
        return FreeKassaSignedNotification::referenceHint($callback);
    }

    public function callbackRouteName(): string
    {
        return 'payments.freekassa.notify';
    }

    /** @param array<string,string> $credentials */
    public function assertCheckout(PaymentCheckout $checkout, PaymentIntent $intent, array $credentials, string $reference): void
    {
        $expected = $this->beginPayment($intent, $credentials, 'test');
        if ($checkout->providerPaymentId !== null || $checkout->redirectUrl !== self::CHECKOUT_URL
            || $checkout->method !== 'GET' || $reference !== $this->referenceForOrder($intent->orderUuid)
            || $checkout->fields !== $expected->fields) {
            throw new InvalidArgumentException('Invalid FreeKassa checkout terms.');
        }
    }

    /** @param array<string,string> $credentials */
    public function verifyCallback(ProviderCallback $callback, array $credentials, string $mode): VerifiedPayment
    {
        $this->assertConfiguration($credentials, $mode);
        $signed = FreeKassaSignedNotification::verify($callback, $credentials['merchant_id'], $credentials['secret_2']);
        $order = DonationOrder::query()->where('order_uuid', $signed->orderUuid)->first();
        if ($order === null || ! in_array((string) $order->currency_code, $this->supportedCurrencies(), true)
            || (int) $order->exponent !== 2) {
            throw new InvalidArgumentException('Invalid FreeKassa order currency.');
        }
        // Currency is from our IMMUTABLE order, not untrusted CUR_ID. Neither
        // callback currency nor actual receiver net is proven by this MD5.
        // Never enable LIVE here until the provider orders API is verified.
        return new VerifiedPayment(
            orderUuid: $signed->orderUuid,
            providerPaymentId: $signed->operationId,
            status: 'received_unsettled',
            currencyCode: (string) $order->currency_code,
            amountMinor: $signed->amountMinor,
            receivedAmountMinor: $signed->amountMinor, // test-only nominal value, not actual net
            testNotification: true, // additional fail-closed guard for this iteration
            environment: 'test',
        );
    }

    private function validSecret(mixed $secret): bool
    {
        return is_string($secret) && strlen($secret) >= 8 && strlen($secret) <= 1024;
    }
}
