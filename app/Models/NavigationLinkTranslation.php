<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 * @property string $title
 */
class NavigationLinkTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['locale', 'title'];

    /** @return BelongsTo<NavigationLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(NavigationLink::class, 'navigation_link_id');
    }
}
