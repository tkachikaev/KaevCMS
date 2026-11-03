<?php

namespace KaevCMS\Modules\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $section_id
 * @property string $locale
 * @property string $name
 */
final class ShopSectionTranslation extends Model
{
    protected $table = 'module_shop_section_translations';

    protected $fillable = ['section_id', 'locale', 'name'];

    protected function casts(): array
    {
        return ['section_id' => 'integer'];
    }
}
