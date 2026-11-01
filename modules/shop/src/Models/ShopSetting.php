<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

final class ShopSetting extends Model
{
    protected $table = 'module_shop_settings';

    protected $fillable = ['setting_key', 'sales_enabled', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return ['sales_enabled' => 'boolean'];
    }
}
