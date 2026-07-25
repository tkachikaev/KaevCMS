<?php

namespace App\Models;

use App\Services\Localization\LanguageManager;
use App\Services\News\NewsHtmlSanitizer;
use App\Services\News\NewsImageStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string $body
 * @property string|null $image
 * @property Carbon|null $published_at
 * @property bool $is_published
 * @property-read Collection<int, NewsTranslation> $translations
 */
class News extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'image',
        'published_at',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    /** @return HasMany<NewsTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(NewsTranslation::class);
    }

    /**
     * @param  Builder<News>  $query
     * @return Builder<News>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->is_published
            && $this->published_at !== null
            && $this->published_at->lte(now());
    }

    public function publicationState(): string
    {
        if (! $this->is_published || $this->published_at === null) {
            return 'draft';
        }

        if ($this->published_at->isFuture()) {
            return 'scheduled';
        }

        return 'published';
    }

    public function publicationLabel(): string
    {
        return match ($this->publicationState()) {
            'published' => __('Published'),
            'scheduled' => __('Scheduled'),
            default => __('Draft'),
        };
    }

    public function translation(?string $locale = null, bool $withFallback = true): ?NewsTranslation
    {
        $locale ??= app()->getLocale();
        $languages = app(LanguageManager::class);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        if (! $this->translationsTableExists()) {
            return null;
        }

        $translations = $this->relationLoaded('translations')
            ? $this->getRelation('translations')
            : $this->translations()->whereIn('locale', $this->translationCandidates($locale, $withFallback))->get();

        if (! $translations instanceof Collection) {
            return null;
        }

        foreach ($this->translationCandidates($locale, $withFallback) as $candidate) {
            $translation = $translations->firstWhere('locale', $candidate);
            if ($translation instanceof NewsTranslation) {
                return $translation;
            }
        }

        return null;
    }

    public function titleFor(?string $locale = null, bool $withFallback = true): string
    {
        $translation = $this->translation($locale, $withFallback);

        return trim((string) ($translation !== null ? $translation->title : $this->title));
    }

    public function slugFor(?string $locale = null, bool $withFallback = true): string
    {
        $translation = $this->translation($locale, $withFallback);

        return trim((string) ($translation !== null ? $translation->slug : $this->slug));
    }

    public function excerptFor(?string $locale = null, bool $withFallback = true): string
    {
        $translation = $this->translation($locale, $withFallback);

        return trim((string) ($translation !== null ? $translation->excerpt : $this->excerpt));
    }

    public function bodyFor(?string $locale = null, bool $withFallback = true): string
    {
        $translation = $this->translation($locale, $withFallback);

        return (string) ($translation !== null ? $translation->body : $this->body);
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->translation($locale, false) !== null;
    }

    public function safeBodyHtml(?string $locale = null): string
    {
        return app(NewsHtmlSanitizer::class)->sanitize($this->bodyFor($locale));
    }

    public function bodyAsPlainText(?string $locale = null): string
    {
        return app(NewsHtmlSanitizer::class)->plainText($this->bodyFor($locale));
    }

    public function coverUrl(): ?string
    {
        $previewUrl = $this->getAttribute('preview_cover_url');

        if (is_string($previewUrl) && str_starts_with($previewUrl, 'data:image/')) {
            return $previewUrl;
        }

        return app(NewsImageStorage::class)->publicUrl($this->image);
    }

    /** @return array<int, string> */
    private function translationCandidates(string $locale, bool $withFallback): array
    {
        if (! $withFallback) {
            return [$locale];
        }

        return app(LanguageManager::class)->fallbackCandidates($locale);
    }

    private function translationsTableExists(): bool
    {
        try {
            return Schema::hasTable('news_translations');
        } catch (Throwable) {
            return false;
        }
    }
}
