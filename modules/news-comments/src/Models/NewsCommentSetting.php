<?php

namespace KaevCMS\Modules\NewsComments\Models;

use Illuminate\Database\Eloquent\Model;

final class NewsCommentSetting extends Model
{
    protected $table = 'module_news_comment_settings';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'enabled',
        'publication_mode',
        'allow_user_editing',
        'allow_editor_moderation',
        'max_comments_per_day',
        'max_comments_per_news_per_day',
        'min_submission_interval_seconds',
        'max_comment_length',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'enabled' => 'boolean',
            'allow_user_editing' => 'boolean',
            'allow_editor_moderation' => 'boolean',
            'max_comments_per_day' => 'integer',
            'max_comments_per_news_per_day' => 'integer',
            'min_submission_interval_seconds' => 'integer',
            'max_comment_length' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }
}
