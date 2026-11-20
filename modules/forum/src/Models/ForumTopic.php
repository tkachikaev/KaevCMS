<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $section_id
 * @property string $status
 * @property bool $is_pinned
 * @property string $author_type
 * @property int|null $author_user_id
 * @property int|null $author_admin_id
 * @property int $reply_count
 * @property Carbon|null $hidden_at
 * @property Carbon|null $deleted_at
 */
final class ForumTopic extends Model
{
    use SoftDeletes;

    protected $table = 'module_forum_topics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'reply_count' => 'integer',
            'last_post_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ForumSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ForumSection::class, 'section_id');
    }

    /** @return HasMany<ForumPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'topic_id');
    }
}
