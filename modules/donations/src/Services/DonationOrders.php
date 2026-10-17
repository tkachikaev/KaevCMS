<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use LogicException;
use RuntimeException;

/**
 * B4 is deliberately provider-free: only draft/cancelled/expired states can be written.
 * No browser amount, payment callback, or wallet mutation is accepted here.
 */
final class DonationOrders
{
    private const DRAFT_MINUTES = 30;

    private const MAX_OPEN_DRAFTS = 5;

    public function __construct(
        private readonly DonationRates $rates,
        private readonly AuditLogger $audit,
    ) {}

    public function createDraft(User $user, string $currencyCode, int $coins, string $requestKey): DonationOrder
    {
        $key = strtolower($requestKey);
        if (! Str::isUuid($key) || ! preg_match('/\A[A-Z]{3}\z/D', $currencyCode)) {
            throw new InvalidArgumentException('Invalid order request.');
        }

        return DB::transaction(function () use ($user, $currencyCode, $coins, $key): DonationOrder {
            // Same stable locking order as the core wallet: user before related rows.
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser instanceof User) {
                throw new ModelNotFoundException;
            }
            $existing = DonationOrder::query()->where('user_id', $lockedUser->getKey())
                ->where('idempotency_key', $key)->first();
            if ($existing instanceof DonationOrder) {
                if ($existing->currency_code !== $currencyCode || (int) $existing->coins !== $coins) {
                    throw new InvalidArgumentException('Order request key was reused with different contents.');
                }

                // Original quote wins on retry, even if rates/visibility have since changed.
                return $existing;
            }
            // Take the same settings-row lock as visibility and tariff updates.
            // A concurrent disable must win before any later draft is admitted.
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->first();
            if (! $settings instanceof DonationSetting
                || ! in_array($settings->getRawOriginal('show_in_account'), [true, 1, '1'], true)) {
                throw new LogicException('New Donations orders are disabled.');
            }
            if (DonationOrder::query()->where('user_id', $lockedUser->getKey())
                ->where('status', DonationOrder::DRAFT)->where('expires_at', '>', now())->count() >= self::MAX_OPEN_DRAFTS) {
                throw new LogicException('Too many open Donations orders.');
            }
            // The locked settings row also serializes administrative tariff updates.
            $quote = $this->rates->quote($currencyCode, $coins);
            $order = new DonationOrder;
            $order->forceFill([
                'order_uuid' => (string) Str::uuid(),
                'user_id' => $lockedUser->getKey(), 'idempotency_key' => $key,
                'coins' => $quote['coins'], 'currency_code' => $quote['currency_code'],
                'exponent' => $quote['exponent'], 'unit_price_minor' => $quote['unit_price_minor'],
                'total_minor' => $quote['total_minor'], 'status' => DonationOrder::DRAFT,
                'expires_at' => now()->addMinutes(self::DRAFT_MINUTES),
            ])->save();
            $this->event($order, null, DonationOrder::DRAFT, 'created');
            $this->auditOrFail('donations.order_created', $lockedUser, $order);

            return $order;
        });
    }

    /** Always scoped to the requesting CMS user; never use an unscoped order UUID in player code. */
    public function forUser(User $user, string $orderUuid): ?DonationOrder
    {
        if (! Str::isUuid($orderUuid)) {
            return null;
        }

        return DonationOrder::query()->where('user_id', $user->getKey())
            ->where('order_uuid', strtolower($orderUuid))->first();
    }

    /** Cancellation is available after disabling new orders; it never changes a wallet. */
    public function cancelDraft(User $user, string $orderUuid): DonationOrder
    {
        if (! Str::isUuid($orderUuid)) {
            throw new InvalidArgumentException('Invalid order reference.');
        }

        return DB::transaction(function () use ($user, $orderUuid): DonationOrder {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser instanceof User) {
                throw new ModelNotFoundException;
            }
            $order = DonationOrder::query()->where('user_id', $lockedUser->getKey())
                ->where('order_uuid', strtolower($orderUuid))->lockForUpdate()->first();
            if (! $order instanceof DonationOrder) {
                throw new ModelNotFoundException;
            }
            if (in_array($order->status, [DonationOrder::CANCELLED, DonationOrder::EXPIRED], true)) {
                return $order;
            }
            if ($order->status !== DonationOrder::DRAFT) {
                throw new LogicException('Payment order cannot be cancelled in its current state.');
            }
            // A verified incoming transfer cannot be dismissed just because new sales closed.
            // Keep the order available for reconciliation or manual review.
            if (DB::table('module_donation_payment_receipts')->where('order_id', $order->id)
                ->whereIn('status', ['pending', 'review', 'credited'])->exists()) {
                throw new LogicException('Verified payment evidence requires settlement review.');
            }
            $to = $order->expires_at->isPast() ? DonationOrder::EXPIRED : DonationOrder::CANCELLED;
            $order->forceFill(['status' => $to])->save();
            $this->event($order, DonationOrder::DRAFT, $to, $to === DonationOrder::EXPIRED ? 'expired' : 'cancelled');
            $this->auditOrFail('donations.order_'.$to, $lockedUser, $order);

            return $order;
        });
    }

    private function event(DonationOrder $order, ?string $from, string $to, string $kind): void
    {
        DB::table('module_donation_order_events')->insert([
            'order_id' => $order->getKey(), 'from_status' => $from, 'to_status' => $to,
            'kind' => $kind, 'created_at' => now(),
        ]);
    }

    private function auditOrFail(string $action, User $actor, DonationOrder $order): void
    {
        if ($this->audit->success(category: 'module', action: $action, actor: $actor,
            target: 'donation-order', details: ['order_uuid' => $order->order_uuid, 'status' => $order->status]) === null) {
            throw new RuntimeException('Unable to audit Donations order operation.');
        }
    }
}
