<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $news_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property-read News $news
 */
final class NewsTranslation extends Model
{
    protected $fillable = [
        'news_id',
        'locale',
        'title',
        'slug',
        'excerpt',
        'body',
    ];

    /** @return BelongsTo<News, $this> */
    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
