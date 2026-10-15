<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable wallet restriction history, independent of audit log retention. */
final class WalletStatusEvent extends Model
{
    protected $table = 'wallet_status_events';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'actor_admin_id' => 'integer',
            'was_blocked' => 'boolean',
            'is_blocked' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
