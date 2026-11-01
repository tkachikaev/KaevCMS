<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShopProduct extends Model
{
    protected $table = 'module_shop_products';

    protected $fillable = [
        'category_id', 'type', 'name_ru', 'name_en', 'price_coins', 'item_id', 'quantity',
        'sort_order', 'is_active', 'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer', 'price_coins' => 'integer', 'item_id' => 'integer',
            'quantity' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShopCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    /** @return HasMany<ShopBundleItem, $this> */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ShopBundleItem::class, 'product_id')->orderBy('id');
    }

    public function nameFor(?string $locale = null): string
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en;
    }
}
