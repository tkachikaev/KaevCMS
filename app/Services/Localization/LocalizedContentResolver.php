<?php

namespace App\Services\Localization;

use App\Models\News;
use App\Models\NewsTranslation;
use App\Models\Page;
use App\Models\PageTranslation;

final class LocalizedContentResolver
{
    public function __construct(private readonly LanguageManager $languages) {}

    public function findPageTranslation(string $locale, string $slug): ?PageTranslation
    {
        foreach ($this->lookupLocales($locale) as $candidate) {
            $translation = PageTranslation::query()
                ->where('locale', $candidate)
                ->where('slug', $slug)
                ->first();

            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
    }

    public function findNewsTranslation(string $locale, string $slug): ?NewsTranslation
    {
        foreach ($this->lookupLocales($locale) as $candidate) {
            $translation = NewsTranslation::query()
                ->where('locale', $candidate)
                ->where('slug', $slug)
                ->first();

            if ($translation !== null) {
                return $translation;
            }
        }

        return null;
    }

    public function pageTranslation(Page $page, string $locale): ?PageTranslation
    {
        $page->loadMissing('translations');

        return $page->translation($locale, true);
    }

    public function newsTranslation(News $news, string $locale): ?NewsTranslation
    {
        $news->loadMissing('translations');

        return $news->translation($locale, true);
    }

    /** @return array{canonicalUrl:string,alternateUrls:array<string,string>,defaultAlternateUrl:string} */
    public function pageMetadata(Page $page, PageTranslation $current): array
    {
        $page->loadMissing('translations');
        $alternateUrls = [];

        foreach ($this->languages->enabledCodes() as $locale) {
            $translation = $page->translation($locale, false);
            if ($translation === null || trim($translation->slug) === '') {
                continue;
            }

            $alternateUrls[$locale] = route('localized.pages.show', [
                'locale' => $locale,
                'slug' => $translation->slug,
            ]);
        }

        $canonicalUrl = route('localized.pages.show', [
            'locale' => $current->locale,
            'slug' => $current->slug,
        ]);

        return [
            'canonicalUrl' => $canonicalUrl,
            'alternateUrls' => $alternateUrls,
            'defaultAlternateUrl' => $this->defaultAlternateUrl($alternateUrls, $canonicalUrl),
        ];
    }

    /** @return array{canonicalUrl:string,alternateUrls:array<string,string>,defaultAlternateUrl:string} */
    public function newsMetadata(News $news, NewsTranslation $current): array
    {
        $news->loadMissing('translations');
        $alternateUrls = [];

        foreach ($this->languages->enabledCodes() as $locale) {
            $translation = $news->translation($locale, false);
            if ($translation === null || trim($translation->slug) === '') {
                continue;
            }

            $alternateUrls[$locale] = route('localized.news.show', [
                'locale' => $locale,
                'slug' => $translation->slug,
            ]);
        }

        $canonicalUrl = route('localized.news.show', [
            'locale' => $current->locale,
            'slug' => $current->slug,
        ]);

        return [
            'canonicalUrl' => $canonicalUrl,
            'alternateUrls' => $alternateUrls,
            'defaultAlternateUrl' => $this->defaultAlternateUrl($alternateUrls, $canonicalUrl),
        ];
    }

    /** @return array<int, string> */
    private function lookupLocales(string $locale): array
    {
        return $this->languages->fallbackCandidates($locale);
    }

    /** @param array<string, string> $alternateUrls */
    private function defaultAlternateUrl(array $alternateUrls, string $canonicalUrl): string
    {
        return $alternateUrls[$this->languages->default()]
            ?? $alternateUrls[$this->languages->fallback()]
            ?? $canonicalUrl;
    }
}
