<?php

namespace App\Http\Middleware;

use App\Services\Localization\LanguageManager;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetApplicationLocale
{
    public function __construct(private readonly LanguageManager $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');
        $locale = is_string($routeLocale) ? $this->languages->normalizeCode($routeLocale) : null;

        if ($request->routeIs('localized.*') && ($locale === null || ! $this->languages->isEnabled($locale))) {
            abort(404);
        }

        if ($locale === null || ! $this->languages->isEnabled($locale)) {
            $isAdmin = $request->routeIs('admin.*');
            $sessionKey = $isAdmin ? 'admin_locale' : 'locale';
            $sessionLocale = $request->hasSession() ? $request->session()->get($sessionKey) : null;
            $locale = is_string($sessionLocale) ? $this->languages->normalizeCode($sessionLocale) : null;

            if ($locale === null || ! $this->languages->isEnabled($locale)) {
                $actor = $isAdmin ? auth('admin')->user() : auth('web')->user();
                $actorLocale = is_object($actor) ? ($actor->locale ?? null) : null;
                $locale = is_string($actorLocale) ? $this->languages->normalizeCode($actorLocale) : null;
            }
        }

        if ($locale === null || ! $this->languages->isEnabled($locale)) {
            $locale = $this->languages->default();
        }

        app()->setLocale($locale);
        config()->set('app.locale', $locale);
        $fallbackLocale = $this->languages->fallback();
        config()->set('app.fallback_locale', $fallbackLocale);
        app('translator')->setFallback($fallbackLocale);
        Carbon::setLocale($locale);

        view()->share('currentLocale', $locale);
        view()->share('enabledLanguages', $this->languages->enabled());
        view()->share('languageDirection', $this->languages->direction($locale));

        return $next($request);
    }
}
