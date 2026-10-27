<?php

namespace KaevCMS\Modules\Donations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable request IDs and original priced terms. All mutations are controlled
 * by PayPalAttempts under SQL row locks; this record is NEVER a payment proof.
 */
final class PayPalAttempt extends Model
{
    protected $table = 'module_donation_paypal_attempts';

    protected $guarded = ['*'];

    /** @return BelongsTo<DonationOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(DonationOrder::class, 'order_id');
    }

    protected function casts(): array
    {
        return [
            'order_id' => 'integer', 'preparation_id' => 'integer',
            'gross_amount_minor' => 'integer', 'exponent' => 'integer',
            'create_started_at' => 'immutable_datetime',
            'capture_started_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }
}
