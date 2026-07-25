<?php

namespace App\Models;

use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reward_inventory_grant_id
 * @property int $user_id
 * @property int $game_server_id
 * @property int $item_id
 * @property string|null $item_name
 * @property int $amount
 * @property string $status
 * @property Carbon|null $transferred_at
 * @property-read RewardInventoryGrant $grant
 * @property-read User $user
 * @property-read GameServer $gameServer
 */
class RewardInventoryItem extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_TRANSFERRED = 'transferred';

    protected $fillable = [
        'reward_inventory_grant_id',
        'user_id',
        'game_server_id',
        'item_id',
        'item_name',
        'amount',
        'status',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_inventory_grant_id' => 'integer',
            'user_id' => 'integer',
            'game_server_id' => 'integer',
            'item_id' => 'integer',
            'amount' => 'integer',
            'transferred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RewardInventoryGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(RewardInventoryGrant::class, 'reward_inventory_grant_id');
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

    /** @return HasMany<RewardDeliveryItem, $this> */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(RewardDeliveryItem::class);
    }

    public function displayName(GameServer|int|null $server = null): string
    {
        return app(GameItemCatalog::class)->displayName(
            $server ?? $this->game_server_id,
            $this->item_id,
            $this->item_name,
        );
    }
}
