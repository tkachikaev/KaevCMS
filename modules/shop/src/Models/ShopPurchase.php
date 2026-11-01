<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<ShopProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class);
    }
}
