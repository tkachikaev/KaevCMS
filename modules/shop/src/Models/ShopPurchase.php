<?php

namespace KaevCMS\Modules\Shop\Models;

use App\Models\GameServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_uuid
 * @property int $user_id
 * @property int $game_server_id
 * @property int $product_id
 * @property string $product_type
 * @property string $product_name_ru
 * @property string $product_name_en
 * @property int $price_coins
 * @property list<array{item_id:int,quantity:int}> $items_snapshot
 * @property int $wallet_entry_id
 * @property int $reward_inventory_grant_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ShopPurchase extends Model
{
    protected $table = 'module_shop_purchases';

    protected $fillable = [
        'request_uuid', 'user_id', 'game_server_id', 'product_id', 'product_type',
        'product_name_ru', 'product_name_en', 'price_coins', 'items_snapshot',
        'wallet_entry_id', 'reward_inventory_grant_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'game_server_id' => 'integer', 'product_id' => 'integer',
            'price_coins' => 'integer', 'items_snapshot' => 'array',
            'wallet_entry_id' => 'integer', 'reward_inventory_grant_id' => 'integer',
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

    public function nameFor(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'en' ? $this->product_name_en : $this->product_name_ru;
    }

    /** @return BelongsTo<ShopProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class);
    }
}
