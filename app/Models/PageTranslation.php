<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property-read Page $page
 */
final class PageTranslation extends Model
{
    protected $fillable = [
        'page_id',
        'locale',
        'title',
        'slug',
        'body',
        'seo_title',
        'seo_description',
    ];

    protected static function booted(): void
    {
        self::saved(static fn () => Page::forgetManagedPages());
        self::deleted(static fn () => Page::forgetManagedPages());
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
