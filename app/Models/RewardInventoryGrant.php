<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $grant_key
 * @property int $user_id
 * @property int $game_server_id
 * @property string $source_type
 * @property string|null $source_reference
 * @property string|null $source_label
 * @property array<string,mixed>|null $metadata
 * @property Carbon|null $granted_at
 * @property-read User $user
 * @property-read GameServer $gameServer
 */
class RewardInventoryGrant extends Model
{
    protected $fillable = [
        'grant_key',
        'user_id',
        'game_server_id',
        'source_type',
        'source_reference',
        'source_label',
        'metadata',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'game_server_id' => 'integer',
            'metadata' => 'array',
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }

    /** @return HasMany<RewardInventoryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RewardInventoryItem::class);
    }
}
