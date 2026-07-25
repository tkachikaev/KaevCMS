<?php

namespace App\Http\Controllers\Localization;

use App\Http\Controllers\Controller;
use App\Services\Localization\LanguageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LocaleController extends Controller
{
    public function public(Request $request, string $locale, LanguageManager $languages): RedirectResponse
    {
        abort_unless($languages->isEnabled($locale), 404);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        $request->session()->put('locale', $locale);

        if ($request->user() !== null && $request->user()->locale !== $locale) {
            $request->user()->forceFill(['locale' => $locale])->save();
        }

        return redirect()->to(localized_current_url($locale, (string) $request->query('return', '/')));
    }

    public function admin(Request $request, string $locale, LanguageManager $languages): RedirectResponse
    {
        abort_unless($languages->isEnabled($locale), 404);
        $locale = $languages->normalizeCode($locale) ?? $languages->default();

        $request->session()->put('admin_locale', $locale);
        $administrator = auth('admin')->user();

        if ($administrator !== null && $administrator->locale !== $locale) {
            $administrator->forceFill(['locale' => $locale])->save();
        }

        return back();
    }
}
