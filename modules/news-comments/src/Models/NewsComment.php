<?php

namespace KaevCMS\Modules\NewsComments\Models;

use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $news_id
 * @property int|null $user_id
 * @property int|null $parent_id
 * @property string|null $body
 * @property string|null $pending_body
 * @property string|null $pending_revision_token
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $pending_edited_at
 * @property \Illuminate\Support\Carbon|null $last_edit_submitted_at
 * @property \Illuminate\Support\Carbon|null $edited_at
 * @property \Illuminate\Support\Carbon|null $deleted_by_admin_at
 * @property \Illuminate\Support\Carbon|null $deleted_by_author_at
 * @property \Illuminate\Support\Carbon|null $moderated_at
 * @property-read User|null $author
 * @property-read NewsComment|null $replyTo
 * @property-read \Illuminate\Database\Eloquent\Collection<int, NewsComment> $replies
 */
final class NewsComment extends Model
{
    protected $table = 'module_news_comments';

    protected $fillable = [
        'news_id',
        'user_id',
        'parent_id',
        'body',
        'pending_body',
        'pending_revision_token',
        'status',
        'pending_edited_at',
        'last_edit_submitted_at',
        'edited_at',
        'deleted_by_author_at',
        'deleted_by_admin_at',
        'moderated_by_admin_id',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return [
            'pending_edited_at' => 'datetime',
            'last_edit_submitted_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_by_author_at' => 'datetime',
            'deleted_by_admin_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<News, $this> */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<NewsComment, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<NewsComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
