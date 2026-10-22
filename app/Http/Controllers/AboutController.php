<?php

namespace App\Http\Controllers;

use App\Services\Localization\LocalizedContentResolver;
use App\Services\Pages\AboutPages;
use Illuminate\View\View;

/** /about: the editable About page text, if published, above the game server cards. */
final class AboutController
{
    public function __invoke(AboutPages $about, LocalizedContentResolver $resolver): View
    {
        $page = $about->selected();
        if ($page !== null && (! $page->isLive() || $resolver->pageTranslation($page, app()->getLocale()) === null)) {
            $page = null;
        }

        return view('theme::pages.about', ['page' => $page]);
    }
}
