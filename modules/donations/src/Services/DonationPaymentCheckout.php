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
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use LogicException;

/**
 * Shared player checkout for an already prepared, reviewed provider order. No payment is submitted
 * on the server; the form POST is a separate, deliberate player action.
 * Browser-provided prices and receivers are never read by this service.
 */
class DonationPaymentCheckout
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
            || $preparation->mode !== 'live'
            || ! in_array($preparation->checkout_method, ['GET', 'POST'], true)
            || $preparation->currency_code !== $order->currency_code
            || (int) $preparation->payer_amount_minor !== (int) $order->total_minor) {
            throw new LogicException('Payment preparation does not match the order.');
        }
        $providerSetting = DonationProviderSetting::query()->whereKey($preparation->provider_code)->first();
        if ($providerSetting === null || ! in_array($providerSetting->getRawOriginal('enabled'), [true, 1, '1'], true)
            || $providerSetting->mode !== 'live'
            || ! in_array($order->currency_code, $providerSetting->currency_codes ?? [], true)) {
            throw new LogicException('Payment provider is not available for checkout.');
        }
        $driver = $this->registry->get((string) $preparation->provider_code);
        if (! $driver instanceof RoutablePaymentProvider) {
            throw new LogicException('Unexpected payment driver.');
        }
        $credentials = $this->providers->credentialsForDriver((string) $preparation->provider_code);
        $returnUrl = route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]);
        $intent = new PaymentIntent(
            (string) $order->order_uuid, (int) $order->coins, (string) $order->currency_code,
            (int) $order->total_minor, (int) $order->exponent,
            route($driver->callbackRouteName()), $returnUrl,
        );
        if ($preparation->provider_label !== $driver->referenceForOrder((string) $order->order_uuid)
            || ! in_array($order->currency_code, $driver->supportedCurrencies(), true)) {
            throw new LogicException('Payment provider and immutable order terms differ.');
        }
        $driver->assertConfiguration($credentials, 'live');
        $checkout = $driver->beginPayment($intent, $credentials, 'live');
        if ($checkout->method !== $preparation->checkout_method
            || ! preg_match('/\Ahttps:\/\/[^\s]+\z/D', $checkout->redirectUrl)) {
            throw new LogicException('Payment checkout differs from prepared terms.');
        }
        // The driver must independently validate its static trusted destination,
        // recipient, immutable amount, return URL and reference.
        $driver->assertCheckout($checkout, $intent, $credentials, (string) $preparation->provider_label);

        return $checkout;
    }
}
