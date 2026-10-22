<?php

namespace App\Models;

use App\Services\CmsSettings;
use Illuminate\Database\Eloquent\Model;

class CmsSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected static function booted(): void
    {
        // CmsSettings reads all rows once per request; any write makes it read them again.
        $forget = static function (): void {
            if (app()->resolved(CmsSettings::class)) {
                app(CmsSettings::class)->forget();
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }
}
