<?php

namespace KaevCMS\Modules\Donations\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Payment-order snapshots are immutable once inserted. All status changes go through DonationOrders.
 *
 * @property int $id
 * @property int $user_id
 * @property string $order_uuid
 * @property int $coins
 * @property string $currency_code
 * @property string $status
 * @property string|null $provider_code
 * @property string|null $provider_payment_id
 * @property int|null $checkout_total_minor
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $credited_at
 */
final class DonationOrder extends Model
{
    public const DRAFT = 'draft';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $table = 'module_donation_orders';

    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Display-only TTL status; no GET request may mutate financial order history. */
    public function displayStatus(): string
    {
        if ($this->status === self::DRAFT && $this->exists
            && \Illuminate\Support\Facades\DB::table('module_donation_payment_receipts')
                ->where('order_id', $this->getKey())->whereIn('status', ['pending', 'review'])->exists()) {
            return 'payment_pending';
        }

        return $this->status === self::DRAFT && $this->expires_at?->isPast()
            ? self::EXPIRED : $this->status;
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'coins' => 'integer', 'exponent' => 'integer',
            'unit_price_minor' => 'integer', 'total_minor' => 'integer',
            'checkout_total_minor' => 'integer',
            'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $order): void {
            if ($order->status !== self::DRAFT || $order->provider_code !== null
                || $order->provider_payment_id !== null || $order->checkout_total_minor !== null
                || $order->paid_at !== null || $order->credited_at !== null) {
                throw new LogicException('Provider-backed order states are unavailable until a trusted provider handler exists.');
            }
        });
        self::updating(function (self $order): void {
            foreach (['order_uuid', 'user_id', 'idempotency_key', 'coins', 'currency_code', 'exponent',
                'unit_price_minor', 'total_minor', 'expires_at', 'created_at', 'provider_code',
                'provider_payment_id', 'checkout_total_minor', 'paid_at', 'credited_at'] as $column) {
                if ($order->isDirty($column)) {
                    throw new LogicException('Donation order snapshot is immutable.');
                }
            }
            if ($order->isDirty('status')
                && ($order->getRawOriginal('status') !== self::DRAFT
                    || ! in_array($order->status, [self::CANCELLED, self::EXPIRED], true))) {
                throw new LogicException('Unsupported donation order transition.');
            }
        });
        self::deleting(static function (): void {
            throw new LogicException('Donation orders may not be deleted.');
        });
    }
}
