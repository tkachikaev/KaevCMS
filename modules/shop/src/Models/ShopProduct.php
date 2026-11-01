<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $category_id
 * @property string $type
 * @property string $name_ru
 * @property string $name_en
 * @property int $price_coins
 * @property int|null $item_id
 * @property int|null $quantity
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $updated_by_admin_id
 */
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

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereBetween('price_coins', [1, 1000000])
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $items) => $items->where('type', 'item')
                    ->whereBetween('item_id', [1, 2147483647])->whereBetween('quantity', [1, 2000000000]))
                ->orWhere(static fn (Builder $bundles) => $bundles->where('type', 'bundle')
                    ->whereHas('bundleItems')->has('bundleItems', '<=', 100)
                    ->whereDoesntHave('bundleItems', static fn (Builder $items) => $items
                        ->whereNotBetween('item_id', [1, 2147483647])
                        ->orWhereNotBetween('quantity', [1, 2000000000]))));
    }

    public function nameFor(?string $locale = null): string
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en;
    }
}
