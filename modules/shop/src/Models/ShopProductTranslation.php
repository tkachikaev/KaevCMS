<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 * @property bool $name_manual
 */
final class ShopProductTranslation extends Model
{
    protected $table = 'module_shop_product_translations';

    protected $fillable = ['product_id', 'locale', 'name', 'description', 'name_manual'];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'name_manual' => 'boolean'];
    }
}
