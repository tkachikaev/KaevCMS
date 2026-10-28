<?php

namespace KaevCMS\Modules\Donations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable request IDs and original priced terms. All mutations are controlled
 * by PayPalAttempts under SQL row locks; this record is NEVER a payment proof.
 *
 * @property int $id
 * @property int|null $order_id
 * @property int|null $preparation_id
 * @property int|null $admin_id
 * @property string $purpose
 * @property string $environment
 * @property string $merchant_identity
 * @property string|null $expected_payee_id
 * @property string $status
 * @property string $create_request_id
 * @property string $capture_request_id
 * @property string $invoice_id
 * @property string|null $paypal_order_id
 * @property string|null $paypal_capture_id
 * @property string|null $approval_url
 * @property int $gross_amount_minor
 * @property string $currency_code
 * @property int $exponent
 * @property string|null $diagnostic_code
 * @property CarbonImmutable|null $create_started_at
 * @property CarbonImmutable|null $capture_started_at
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $created_at
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer', 'preparation_id' => 'integer',
            'gross_amount_minor' => 'integer', 'exponent' => 'integer',
            'create_started_at' => 'immutable_datetime',
            'capture_started_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
