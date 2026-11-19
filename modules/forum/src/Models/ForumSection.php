<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ForumSection extends Model
{
    use SoftDeletes;

    protected $table = 'module_forum_sections';

    protected $guarded = ['id'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
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
