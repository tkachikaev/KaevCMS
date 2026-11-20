<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $topic_id
 * @property string $author_type
 * @property int|null $author_user_id
 * @property int|null $author_admin_id
 * @property string|null $author_role
 * @property string $body_text
 * @property string|null $body_html
 * @property int $edit_version
 * @property Carbon|null $removed_at
 * @property Carbon|null $deleted_at
 */
final class ForumPost extends Model
{
    use SoftDeletes;

    protected $table = 'module_forum_posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'removed_at' => 'datetime',
            'edit_version' => 'integer',
        ];
    }

    /** @return BelongsTo<ForumTopic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ForumTopic::class, 'topic_id');
    }
}
