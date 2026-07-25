<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\Localization\LanguageManager;
use App\Services\Localization\LocalizedContentResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class PageController
{
    public function show(Page $page, LocalizedContentResolver $resolver): RedirectResponse
    {
        abort_unless($page->isLive(), 404);

        $translation = $resolver->pageTranslation($page, app()->getLocale());

        abort_if($translation === null, 404);

        return redirect()->route('localized.pages.show', [
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

        $matchedTranslation = $resolver->findPageTranslation($locale, $slug);
        abort_if($matchedTranslation === null, 404);

        /** @var Page $page */
        $page = $matchedTranslation->page()->with('translations')->firstOrFail();
        abort_unless($page->isLive(), 404);

        $translation = $resolver->pageTranslation($page, $locale);
        abort_if($translation === null, 404);

        $canonicalParameters = [
            'locale' => $translation->locale,
            'slug' => $translation->slug,
        ];

        if ($locale !== $translation->locale || $slug !== $translation->slug) {
            return redirect()->route(
                'localized.pages.show',
                $canonicalParameters,
                $locale === $translation->locale ? 301 : 302,
            );
        }

        return view('theme::pages.show', array_merge(
            ['page' => $page],
            $resolver->pageMetadata($page, $translation),
        ));
    }
}
