<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ForumCategory extends Model
{
    use SoftDeletes;

    protected $table = 'module_forum_categories';

    protected $guarded = ['id'];

    public function sections(): HasMany
    {
        return $this->hasMany(ForumSection::class, 'category_id');
    }

    public function label(): string
    {
        return app()->getLocale() === 'en' && filled($this->name_en)
            ? $this->name_en : $this->name_ru;
    }

    public function description(): ?string
    {
        return app()->getLocale() === 'en' && filled($this->description_en)
            ? $this->description_en : $this->description_ru;
    }
}
