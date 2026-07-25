<?php

namespace App\Models;

use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $reward_delivery_id
 * @property int $reward_inventory_item_id
 * @property int $item_id
 * @property string|null $item_name
 * @property int $amount
 * @property-read RewardDelivery $delivery
 * @property-read RewardInventoryItem $inventoryItem
 */
class RewardDeliveryItem extends Model
{
    protected $fillable = [
        'reward_delivery_id',
        'reward_inventory_item_id',
        'item_id',
        'item_name',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'reward_delivery_id' => 'integer',
            'reward_inventory_item_id' => 'integer',
            'item_id' => 'integer',
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<RewardDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(RewardDelivery::class, 'reward_delivery_id');
    }

    /** @return BelongsTo<RewardInventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(RewardInventoryItem::class, 'reward_inventory_item_id');
    }

    public function displayName(GameServer|int|null $server = null): string
    {
        return app(GameItemCatalog::class)->displayName(
            $server,
            $this->item_id,
            $this->item_name,
        );
    }
}
