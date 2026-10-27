<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\PayPalAttempt;
use LogicException;

/** A browser return triggers server-side REST confirmation; no browser flag can credit a wallet. */
final class PayPalLiveReturns
{
    public function __construct(
        private readonly PayPalAdminSettings $settings,
        private readonly PayPalOrderOperations $operations,
        private readonly PayPalVerifiedPayments $verified,
    ) {}

    public function returned(User $user, DonationOrder $order, string $token): ?string
    {
        if ((int) $order->user_id !== (int) $user->getKey() || $order->order_uuid === '') {
            throw new LogicException('PayPal return belongs to another user.');
        }
        $attempt = PayPalAttempt::query()->where('order_id', $order->getKey())->first();
        if ($attempt === null || $attempt->purpose !== 'player' || $attempt->environment !== 'live'
            || $attempt->paypal_order_id === null || ! hash_equals($attempt->paypal_order_id, $token)) {
            throw new InvalidArgumentException('Invalid PayPal return token.');
        }
        if ($attempt->status === 'settled') {
            return 'credited';
        }
        $credentials = $this->settings->credentials('live');
        // After timeout/unknown capture: read only, never a second POST.
        if (in_array($attempt->status, ['created', 'approved'], true)) {
            $this->operations->capture((int) $attempt->id, $credentials);
        }
        return $this->verified->reconcile((int) $attempt->id);
    }
}
