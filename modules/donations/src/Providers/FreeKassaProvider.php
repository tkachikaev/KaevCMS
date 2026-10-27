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
use KaevCMS\Modules\Donations\Services\FreeKassaOrderApi;

/**
 * SCI driver. For live payments, every callback requires independent server-side
 * API confirmation of canonical provider order ID, immutable amount, currency and PAID status.
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

    public function __construct(private readonly ?FreeKassaOrderApi $orderApi = null) {}

    /** @param array<string,string> $credentials */
    public function assertConfiguration(array $credentials, string $mode): void
    {
        if (! in_array($mode, ['test', 'live'], true)
            || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', $credentials['merchant_id'] ?? '')
            || ! $this->validSecret($credentials['secret_1'] ?? null)
            || ! $this->validSecret($credentials['secret_2'] ?? null)
            || (array_key_exists('api_key', $credentials)
                && $credentials['api_key'] !== '' && ! $this->validSecret($credentials['api_key']))) {
            throw new InvalidArgumentException('Invalid FreeKassa configuration.');
        }
        // A live callback alone cannot prove the received currency or status.
        // Therefore an API key is mandatory for any real player checkout.
        if ($mode === 'live' && ! $this->validSecret($credentials['api_key'] ?? null)) {
            throw new InvalidArgumentException('FreeKassa API key is required for live payments.');
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
        $expected = $this->beginPayment($intent, $credentials,
            isset($credentials['api_key']) && $credentials['api_key'] !== '' ? 'live' : 'test');
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
        $live = $mode === 'live';
        if ($live) {
            // Query the authenticated orders API before any settlement/credit.
            // Do not verify remote state while holding Wallet DB row locks.
            $canonicalId = ($this->orderApi ?? app(FreeKassaOrderApi::class))->assertPaid(
                $credentials['merchant_id'], $credentials['api_key'], $signed->orderUuid,
                $signed->operationId, $signed->amountMinor, (string) $order->currency_code,
            );
        }

        return new VerifiedPayment(
            orderUuid: $signed->orderUuid,
            providerPaymentId: $canonicalId ?? $signed->operationId,
            status: 'received_unsettled',
            currencyCode: (string) $order->currency_code,
            amountMinor: $signed->amountMinor,
            receivedAmountMinor: $signed->amountMinor, // API confirms gross; no signed receiver-net field is available
            testNotification: ! $live,
            environment: $live ? 'live' : 'test',
        );
    }

    private function validSecret(mixed $secret): bool
    {
        return is_string($secret) && strlen($secret) >= 8 && strlen($secret) <= 1024;
    }
}
