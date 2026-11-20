<?php

namespace KaevCMS\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function topic(): BelongsTo
    {
        return $this->belongsTo(ForumTopic::class, 'topic_id');
    }
}
