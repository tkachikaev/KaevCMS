<?php

namespace KaevCMS\Modules\Shop\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KaevCMS\Modules\Shop\Models\Concerns\HasShopTranslations;

/**
 * A catalog section of one GameServer (the table keeps its historical "tabs" name).
 *
 * @property int $id
 * @property int $game_server_id
 * @property string $name_ru
 * @property string $name_en
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $updated_by_admin_id
 */
final class ShopTab extends Model
{
    use HasShopTranslations;

    protected $table = 'module_shop_tabs';

    protected $fillable = ['game_server_id', 'name_ru', 'name_en', 'sort_order', 'is_active', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['game_server_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<GameServer, $this> */
    public function gameServer(): BelongsTo
    {
        return $this->belongsTo(GameServer::class);
    }

    /** @return HasMany<ShopCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(ShopCategory::class, 'tab_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ShopProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'section_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ShopSectionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ShopSectionTranslation::class, 'section_id');
    }

    public function nameFor(?string $locale = null): string
    {
        return $this->translated('name', $locale)
            ?? (str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en);
    }
}
