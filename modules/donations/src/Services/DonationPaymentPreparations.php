<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\DeferredCheckoutProvider;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use LogicException;
use RuntimeException;

/**
 * Associates an immutable, server-priced draft and a trusted provider.
 * C5 renders a separate checkout only behind four trusted server-side gates.
 */
final class DonationPaymentPreparations
{
    public function __construct(
        private readonly DonationProviderRegistry $registry,
        private readonly DonationProviderSettings $providers,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array{code:string,name:string,mode:string}> */
    public function available(string $currencyCode): array
    {
        $result = [];
        foreach ($this->registry->all() as $code => $driver) {
            if (! $driver instanceof RoutablePaymentProvider) {
                continue; // Unreviewed drivers cannot prepare payments.
            }
            $state = $this->providers->localDiagnostic($code);
            if ($state['state'] !== 'local_ok' || ! in_array($currencyCode, $state['matched_currencies'], true)) {
                continue;
            }
            $row = DonationProviderSetting::query()->find($code);
            // FreeKassa test checkouts belong strictly to the admin workflow.
            if (in_array($code, ['freekassa', 'paypal'], true) && $row?->mode !== 'live') {
                continue;
            }
            if ($row !== null) {
                $result[] = ['code' => $code, 'name' => $driver->name(), 'mode' => $row->mode];
            }
        }

        return $result;
    }

    public function forOrder(DonationOrder $order): ?DonationPaymentPreparation
    {
        return DonationPaymentPreparation::query()->where('order_id', $order->getKey())->first();
    }

    public function prepare(User $user, string $orderUuid, string $providerCode): DonationPaymentPreparation
    {
        if (! preg_match('/\A[0-9a-f-]{36}\z/D', $orderUuid)
            || ! preg_match('/\A[a-z][a-z0-9_-]{1,39}\z/D', $providerCode)) {
            throw new InvalidArgumentException('Invalid payment preparation.');
        }
        $driver = $this->registry->get($providerCode); // Trusted PHP allowlist, not DB class name.
        if (! $driver instanceof RoutablePaymentProvider) {
            throw new LogicException('Payment provider has no reviewed checkout contract.');
        }

        return DB::transaction(function () use ($user, $orderUuid, $providerCode, $driver): DonationPaymentPreparation {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser instanceof User) {
                throw new ModelNotFoundException;
            }
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->first();
            if ($settings === null || ! in_array($settings->getRawOriginal('show_in_account'), [true, 1, '1'], true)) {
                throw new LogicException('New payment preparations are closed.');
            }
            $order = DonationOrder::query()->where('user_id', $lockedUser->getKey())
                ->where('order_uuid', $orderUuid)->lockForUpdate()->first();
            if (! $order instanceof DonationOrder) {
                throw new ModelNotFoundException;
            }
            $existing = $this->forOrder($order);
            if ($existing !== null) {
                if ($existing->provider_code !== $providerCode) {
                    throw new LogicException('An order can only have one provider.');
                }

                return $existing; // Deterministic replay, even if prices or config changed.
            }
            if ($order->status !== DonationOrder::DRAFT || $order->expires_at->isPast()) {
                throw new LogicException('Payment draft has expired or was closed.');
            }
            $row = DonationProviderSetting::query()->whereKey($providerCode)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->getRawOriginal('enabled'), [true, 1, '1'], true)
                || ! in_array($row->mode, ['test', 'live'], true)
                || ! in_array($order->currency_code, $row->currency_codes ?? [], true)
                || ! in_array($order->currency_code, $driver->supportedCurrencies(), true)) {
                throw new LogicException('Provider not available for this currency.');
            }
            if (in_array($providerCode, ['freekassa', 'paypal'], true) && $row->mode !== 'live') {
                throw new LogicException('Provider test checkout is restricted to administrators.');
            }
            // Check rate was not disabled, but never reprice the existing order.
            if (! in_array($order->currency_code, $this->providers->localDiagnostic($providerCode)['matched_currencies'], true)) {
                throw new LogicException('Provider rate is not available.');
            }
            $credentials = $this->providers->credentialsForDriver($providerCode);
            $driver->assertConfiguration($credentials, (string) $row->mode);
            $method = 'PREVIEW';
            $label = $driver->referenceForOrder((string) $order->order_uuid);
            if ($label === '' || strlen($label) > 64
                || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $label)) {
                throw new LogicException('Invalid provider payment reference.');
            }
            // Test mode prepares a non-executable preview. Live preparations are
            // valid only when the trusted driver verifies its checkout terms.
            if ($row->mode === 'live' && ! $driver instanceof DeferredCheckoutProvider) {
                $intent = new PaymentIntent(
                    (string) $order->order_uuid, (int) $order->coins, (string) $order->currency_code,
                    (int) $order->total_minor, (int) $order->exponent,
                    route($driver->callbackRouteName()),
                    route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]),
                );
                $checkout = $driver->beginPayment($intent, $credentials, 'live');
                if (! in_array($checkout->method, ['GET', 'POST'], true)
                    || ! preg_match('/\Ahttps:\/\/[^\s]+\z/D', $checkout->redirectUrl)) {
                    throw new LogicException('Unsafe payment checkout.');
                }
                $driver->assertCheckout($checkout, $intent, $credentials, $label);
                $method = $checkout->method;
            }
            $record = new DonationPaymentPreparation;
            $record->forceFill([
                'order_id' => $order->getKey(), 'provider_code' => $providerCode,
                'mode' => $row->mode, 'checkout_method' => $driver instanceof DeferredCheckoutProvider ? 'GET' : $method,
                'provider_label' => $label, 'payer_amount_minor' => $order->total_minor,
                'currency_code' => $order->currency_code,
            ])->save();
            if ($this->audit->success(category: 'module', action: 'donations.payment_prepared', actor: $lockedUser,
                target: 'donation-order', details: [
                    'order_uuid' => $order->order_uuid, 'provider_code' => $providerCode,
                    'mode' => $row->mode, 'phase' => 'preview_only',
                ]) === null) {
                throw new RuntimeException('Unable to audit payment preparation.');
            }

            return $record;
        });
    }
}
