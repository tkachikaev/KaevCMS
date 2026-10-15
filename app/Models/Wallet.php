<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $balance
 * @property bool $is_blocked
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WalletEntry> $entries
 */
class Wallet extends Model
{
    // Wallet state must not be editable through ordinary mass assignment.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'balance' => 'integer',
            'is_blocked' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }
}
