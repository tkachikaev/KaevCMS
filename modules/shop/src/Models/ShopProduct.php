<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KaevCMS\Modules\Shop\Models\Concerns\HasShopTranslations;

/**
 * @property int $id
 * @property int|null $category_id
 * @property int|null $section_id
 * @property string|null $description_ru
 * @property string|null $description_en
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
    use HasShopTranslations;

    protected $table = 'module_shop_products';

    protected $fillable = [
        'category_id', 'section_id', 'type', 'name_ru', 'name_en', 'description_ru', 'description_en', 'price_coins', 'item_id', 'quantity',
        'sort_order', 'is_active', 'updated_by_admin_id',
    ];

    protected static function booted(): void
    {
        // Older API callers still create products via a category. Preserve
        // their section link while the legacy routes remain available.
        self::creating(static function (self $product): void {
            if ($product->section_id === null && $product->category_id !== null) {
                $product->section_id = ShopCategory::query()->whereKey($product->category_id)->value('tab_id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category_id' => 'integer', 'section_id' => 'integer', 'price_coins' => 'integer', 'item_id' => 'integer',
            'quantity' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShopCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    // Canonical section relation. The nullable category is legacy metadata only.
    /** @return BelongsTo<ShopTab, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ShopTab::class, 'section_id');
    }

    /** @return HasMany<ShopProductTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ShopProductTranslation::class, 'product_id');
    }

    public function descriptionFor(?string $locale = null): string
    {
        return $this->translated('description', $locale)
            ?? (str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru')
                ? (string) $this->description_ru : (string) $this->description_en);
    }

    /**
     * Visible to players: the product and its section are shown and a legacy
     * category, where one is still attached, is not hidden.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleInCatalog(Builder $query): Builder
    {
        return $query->published()
            ->whereHas('section', static fn (Builder $section) => $section->where('is_active', true))
            ->where(static fn (Builder $legacy) => $legacy->whereNull('category_id')
                ->orWhereHas('category', static fn (Builder $category) => $category->where('is_active', true)));
    }

    /** @return list<array{item_id:int, quantity:int}> */
    public function contents(): array
    {
        if ($this->type === 'item') {
            return [['item_id' => (int) $this->item_id, 'quantity' => (int) $this->quantity]];
        }

        return $this->bundleItems->map(static fn (ShopBundleItem $item): array => [
            'item_id' => (int) $item->item_id, 'quantity' => (int) $item->quantity,
        ])->values()->all();
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
        return $this->translated('name', $locale)
            ?? (str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en);
    }
}
