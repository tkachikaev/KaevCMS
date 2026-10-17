<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Donations\Data\PaymentCheckout;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;
use LogicException;

/**
 * Player checkout for an already prepared YooMoney order. No payment is submitted
 * on the server; the form POST is a separate, deliberate player action.
 * Browser-provided prices and receivers are never read by this service.
 */
final class DonationYooMoneyCheckout
{
    public function __construct(
        private readonly DonationProviderRegistry $registry,
        private readonly DonationProviderSettings $providers,
        private readonly DonationSettings $settings,
        private readonly DonationMoney $money,
    ) {}

    /** Trusted integration guards plus audited payment mode in the database. */
    public static function enabled(): bool
    {
        return config('donation-payments.checkout_enabled') === true
            && config('donation-payments.callbacks_enabled') === true
            && config('donation-payments.credits_enabled') === true
            && config('wallet.enabled_sources.donations') === true
            && app(DonationLiveActivation::class)->state()['mode'] !== 'off';
    }

    /** Scope a first live payment to one CMS account without disabling callbacks. */
    public static function allowedForUser(User $user): bool
    {
        return app(DonationLiveActivation::class)->allows($user);
    }

    public function available(User $user, DonationOrder $order): bool
    {
        if (! self::enabled()) {
            return false;
        }

        try {
            $this->build($user, $order);

            return true;
        } catch (ModelNotFoundException|LogicException|\InvalidArgumentException|\RuntimeException) {
            return false;
        }
    }

    public function build(User $user, DonationOrder $order): PaymentCheckout
    {
        if ((int) $order->user_id !== (int) $user->getKey()) {
            throw new ModelNotFoundException;
        }
        if (! self::enabled() || ! self::allowedForUser($user) || ! $this->settings->isVisibleToPlayers()
            || $order->status !== DonationOrder::DRAFT || $order->expires_at === null
            || $order->expires_at->isPast()) {
            throw new LogicException('Live checkout is not available.');
        }

        // A late or conflicting signed receipt must not cause another checkout.
        if (DB::table('module_donation_payment_receipts')->where('order_id', $order->getKey())->exists()) {
            throw new LogicException('This order already has payment evidence.');
        }
        $preparation = DonationPaymentPreparation::query()->where('order_id', $order->getKey())->first();
        if (! $preparation instanceof DonationPaymentPreparation
            || $preparation->provider_code !== 'yoomoney' || $preparation->mode !== 'live'
            || $preparation->checkout_method !== 'POST'
            || $preparation->provider_label !== YooMoneyProvider::label((string) $order->order_uuid)
            || $preparation->currency_code !== 'RUB' || $order->currency_code !== 'RUB'
            || (int) $order->exponent !== 2
            || (int) $preparation->payer_amount_minor !== (int) $order->total_minor) {
            throw new LogicException('Payment preparation does not match the order.');
        }
        $providerSetting = DonationProviderSetting::query()->whereKey('yoomoney')->first();
        if ($providerSetting === null || ! in_array($providerSetting->getRawOriginal('enabled'), [true, 1, '1'], true)
            || $providerSetting->mode !== 'live'
            || ! in_array('RUB', $providerSetting->currency_codes ?? [], true)) {
            throw new LogicException('YooMoney is not available for checkout.');
        }
        $driver = $this->registry->get('yoomoney');
        if (! $driver instanceof YooMoneyProvider) {
            throw new LogicException('Unexpected payment driver.');
        }
        $credentials = $this->providers->credentialsForDriver('yoomoney');
        $returnUrl = route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]);
        $checkout = $driver->beginPayment(new PaymentIntent(
            (string) $order->order_uuid, (int) $order->coins, 'RUB',
            (int) $order->total_minor, 2,
            route('payments.yoomoney.notify'), $returnUrl,
        ), $credentials, 'live');

        // Do not accept a driver redirect that can be reconfigured from the DB.
        if ($checkout->redirectUrl !== YooMoneyProvider::CHECKOUT_URL || $checkout->method !== 'POST'
            || ($checkout->fields['receiver'] ?? null) !== ($credentials['receiver'] ?? null)
            || ($checkout->fields['label'] ?? null) !== $preparation->provider_label
            || ($checkout->fields['sum'] ?? null) !== $this->money->format((int) $order->total_minor, 2)
            || ($checkout->fields['quickpay-form'] ?? null) !== 'button'
            || ($checkout->fields['paymentType'] ?? null) !== 'PC'
            || ($checkout->fields['successURL'] ?? null) !== $returnUrl) {
            throw new LogicException('Invalid trusted checkout terms.');
        }

        return $checkout;
    }
}
