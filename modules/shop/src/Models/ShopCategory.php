<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function nameFor(?string $locale = null): string
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en;
    }
}
