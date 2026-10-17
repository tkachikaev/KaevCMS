<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;
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
            if ($code !== 'yoomoney') {
                continue; // Other drivers must opt in to a reviewed preparation flow.
            }
            $state = $this->providers->localDiagnostic($code);
            if ($state['state'] !== 'local_ok' || ! in_array($currencyCode, $state['matched_currencies'], true)) {
                continue;
            }
            $row = DonationProviderSetting::query()->find($code);
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
            // Check rate was not disabled, but never reprice the existing order.
            if (! in_array($order->currency_code, $this->providers->localDiagnostic($providerCode)['matched_currencies'], true)) {
                throw new LogicException('Provider rate is not available.');
            }
            $credentials = $this->providers->credentialsForDriver($providerCode);
            $driver->assertConfiguration($credentials, (string) $row->mode);
            $method = 'PREVIEW';
            $label = YooMoneyProvider::label($order->order_uuid);
            if ($providerCode === 'yoomoney') {
                // In test mode no payment endpoint exists. In live, build a DTO but
                // NEVER expose URL/fields to the player until C3 safeguards are ready.
                if ($row->mode === 'live') {
                    $checkout = $driver->beginPayment(new PaymentIntent(
                        $order->order_uuid, (int) $order->coins, $order->currency_code,
                        (int) $order->total_minor, (int) $order->exponent, '',
                        route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]),
                    ), $credentials, 'live');
                    if ($checkout->method !== 'POST' || $checkout->redirectUrl !== YooMoneyProvider::CHECKOUT_URL
                        || ($checkout->fields['sum'] ?? null) !== (new DonationMoney)->format((int) $order->total_minor, 2)
                        || ($checkout->fields['label'] ?? null) !== $label) {
                        throw new LogicException('Invalid checkout terms.');
                    }
                    $method = $checkout->method;
                }
            } else {
                // Future trusted providers require an explicit implementation here.
                throw new LogicException('Provider preparation not supported yet.');
            }
            $record = new DonationPaymentPreparation;
            $record->forceFill([
                'order_id' => $order->getKey(), 'provider_code' => $providerCode,
                'mode' => $row->mode, 'checkout_method' => $method,
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
