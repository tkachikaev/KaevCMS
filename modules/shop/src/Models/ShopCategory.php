<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tab_id
 * @property string $name_ru
 * @property string $name_en
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $updated_by_admin_id
 */
final class ShopCategory extends Model
{
    protected $table = 'module_shop_categories';

    protected $fillable = ['tab_id', 'name_ru', 'name_en', 'sort_order', 'is_active', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['tab_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<ShopTab, $this> */
    public function tab(): BelongsTo
    {
        return $this->belongsTo(ShopTab::class, 'tab_id');
    }

    /** @return HasMany<ShopProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'category_id');
    }

    public function nameFor(?string $locale = null): string
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en;
    }
}
