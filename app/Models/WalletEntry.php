<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only wallet ledger representation. Writes are reserved for the atomic
 * wallet engine introduced in A2; no generic mass-assignable entry API exists.
 *
 * @property int $id
 * @property int $wallet_id
 * @property string $operation_uuid
 * @property string $source
 * @property string $source_key
 * @property string $direction
 * @property int $amount
 * @property int $balance_before
 * @property int $balance_after
 * @property-read Wallet $wallet
 */
class WalletEntry extends Model
{
    protected $table = 'wallet_entries';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'amount' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'actor_user_id' => 'integer',
            'actor_admin_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
