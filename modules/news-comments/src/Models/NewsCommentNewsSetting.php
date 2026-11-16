<?php

namespace KaevCMS\Modules\NewsComments\Models;

use Illuminate\Database\Eloquent\Model;

final class NewsCommentNewsSetting extends Model
{
    protected $table = 'module_news_comment_news_settings';

    protected $primaryKey = 'news_id';

    public $incrementing = false;

    protected $fillable = ['news_id', 'policy', 'updated_by_admin_id'];
}
