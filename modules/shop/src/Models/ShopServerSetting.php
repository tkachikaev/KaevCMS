<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $game_server_id
 * @property bool $enabled
 * @property int|null $updated_by_admin_id
 */
final class ShopServerSetting extends Model
{
    protected $table = 'module_shop_server_settings';

    protected $fillable = ['game_server_id', 'enabled', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
