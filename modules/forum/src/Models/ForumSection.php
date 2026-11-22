<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $category_id
 * @property string $name_ru
 * @property string|null $name_en
 * @property string|null $description_ru
 * @property string|null $description_en
 * @property string $view_rule
 * @property string $topic_rule
 * @property string $reply_rule
 * @property Carbon|null $deleted_at
 */
final class ForumSection extends Model
{
    use SoftDeletes;

    protected $table = 'module_forum_sections';

    protected $guarded = ['id'];

    /** @return BelongsTo<ForumCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    /** @return HasMany<ForumTopic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(ForumTopic::class, 'section_id');
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
