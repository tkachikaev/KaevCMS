<?php

namespace App\Models;

use App\Services\Localization\LanguageManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $url
 * @property bool $is_active
 * @property bool $show_in_header
 * @property bool $show_in_footer
 * @property bool $open_new_tab
 * @property int $sort_order
 * @property-read Collection<int, NavigationLinkTranslation> $translations
 */
class NavigationLink extends Model
{
    protected $fillable = [
        'url',
        'is_active',
        'show_in_header',
        'show_in_footer',
        'open_new_tab',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_header' => 'boolean',
            'show_in_footer' => 'boolean',
            'open_new_tab' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<NavigationLinkTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(NavigationLinkTranslation::class);
    }

    /**
     * @param  Builder<NavigationLink>  $query
     * @return Builder<NavigationLink>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function titleFor(?string $locale = null): string
    {
        $languages = app(LanguageManager::class);
        $code = $languages->normalizeCode($locale ?? app()->getLocale()) ?? $languages->default();
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        foreach ($languages->fallbackCandidates($code) as $candidate) {
            $translation = $translations->firstWhere('locale', $candidate);
            if ($translation instanceof NavigationLinkTranslation) {
                return $translation->title;
            }
        }

        return '';
    }

    public static function isSafeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
