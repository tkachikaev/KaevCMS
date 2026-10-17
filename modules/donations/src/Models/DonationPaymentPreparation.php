<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * C2 intent snapshot; no money can be sent or credited by this record.
 *
 * @property int $id
 * @property int $order_id
 * @property string $provider_code
 * @property string $mode
 * @property string $checkout_method
 * @property string $provider_label
 * @property int $payer_amount_minor
 * @property string $currency_code
 */
final class DonationPaymentPreparation extends Model
{
    /** @return BelongsTo<DonationOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(DonationOrder::class, 'order_id');
    }

    protected $table = 'module_donation_payment_preparations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'payer_amount_minor' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Payment preparations are immutable.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Payment preparations cannot be deleted.');
        });
    }
}
