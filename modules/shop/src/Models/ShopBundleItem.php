<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $item_id
 * @property int $quantity
 */
final class ShopBundleItem extends Model
{
    protected $table = 'module_shop_bundle_items';

    protected $fillable = ['product_id', 'item_id', 'quantity'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'item_id' => 'integer', 'quantity' => 'integer'];
    }

    /** @return BelongsTo<ShopProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'product_id');
    }
}
