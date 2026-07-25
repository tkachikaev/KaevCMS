<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Services\Localization\LanguageManager;
use App\Services\Localization\LocalizedContentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class NewsController
{
    public function index(): View
    {
        return view('theme::news.index', [
            'news' => News::query()
                ->with('translations')
                ->published()
                ->latest('published_at')
                ->paginate(10),
        ]);
    }

    public function show(News $news, LocalizedContentResolver $resolver): View|RedirectResponse
    {
        abort_unless($news->isLive(), 404);

        $translation = $resolver->newsTranslation($news, app()->getLocale());

        if ($translation === null) {
            return view('theme::news.show', [
                'news' => $news,
                'canonicalUrl' => route('news.show', ['news' => $news]),
                'alternateUrls' => [],
                'defaultAlternateUrl' => route('news.show', ['news' => $news]),
            ]);
        }

        return redirect()->route('localized.news.show', [
            'locale' => $translation->locale,
            'slug' => $translation->slug,
        ], 302);
    }

    public function showLocalized(
        string $locale,
        string $slug,
        LanguageManager $languages,
        LocalizedContentResolver $resolver,
    ): View|RedirectResponse {
        abort_unless($languages->isEnabled($locale), 404);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        $matchedTranslation = $resolver->findNewsTranslation($locale, $slug);
        abort_if($matchedTranslation === null, 404);

        /** @var News $news */
        $news = $matchedTranslation->news()->with('translations')->firstOrFail();
        abort_unless($news->isLive(), 404);

        $translation = $resolver->newsTranslation($news, $locale);
        abort_if($translation === null, 404);

        $canonicalParameters = [
            'locale' => $translation->locale,
            'slug' => $translation->slug,
        ];

        if ($locale !== $translation->locale || $slug !== $translation->slug) {
            return redirect()->route(
                'localized.news.show',
                $canonicalParameters,
                $locale === $translation->locale ? 301 : 302,
            );
        }

        return view('theme::news.show', array_merge(
            ['news' => $news],
            $resolver->newsMetadata($news, $translation),
        ));
    }
}
