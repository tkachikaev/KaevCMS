<?php

namespace App\Providers;

use App\Services\CmsSettings;
use App\Services\GameWorld\GameStatistics;
use App\Services\Pages\PageNavigation;
use App\Support\Themes\AccountThemeManager;
use App\Support\Themes\ThemeManager;
use App\Support\Themes\ThemeValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

class ThemeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CmsSettings::class);
        $this->app->singleton(ThemeValidator::class);
        $this->app->singleton(ThemeManager::class, fn ($app) => new ThemeManager(
            themesPath: config('cms.themes_path'),
            publicThemesPath: public_path('themes'),
            fallbackTheme: config('cms.theme'),
            settings: $app->make(CmsSettings::class),
            files: $app->make(Filesystem::class),
            validator: $app->make(ThemeValidator::class),
        ));
        $this->app->singleton(AccountThemeManager::class, fn ($app) => new AccountThemeManager(
            themesPath: config('cms.account_themes_path'),
            publicThemesPath: public_path('account-themes'),
            fallbackTheme: config('cms.account_theme'),
            settings: $app->make(CmsSettings::class),
            files: $app->make(Filesystem::class),
            validator: $app->make(ThemeValidator::class),
        ));
    }

    public function boot(ThemeManager $themes, AccountThemeManager $accountThemes, PageNavigation $pages): void
    {
        $themes->boot();
        $accountThemes->boot();
        view()->share('activeTheme', $themes->manifest());
        view()->share('activeAccountTheme', $accountThemes->manifest());

        view()->composer('theme::partials.header', function ($view) use ($pages): void {
            $view->with([
                'headerPages' => $pages->header(),
                'statisticsNavigationAvailable' => app(GameStatistics::class)->navigationAvailable(),
            ]);
        });

        view()->composer('theme::partials.footer', function ($view) use ($pages): void {
            $view->with('footerPages', $pages->footer());
        });
    }
}
