<?php

namespace KaevCMS\Modules\Shop\Models;

use App\Models\GameServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ShopTab extends Model
{
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

    public function nameFor(?string $locale = null): string
    {
        return str_starts_with(strtolower($locale ?? app()->getLocale()), 'ru') ? $this->name_ru : $this->name_en;
    }
}
