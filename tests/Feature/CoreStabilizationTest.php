<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminPanelSettingsController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\MailSettingsController;
use App\Http\Controllers\Admin\SystemSettingsController;
use App\Services\Html\SafeHtmlSanitizer;
use App\Services\Mail\CustomMailHtmlSanitizer;
use App\Services\News\NewsHtmlSanitizer;
use App\Services\Pages\PageHtmlSanitizer;
use App\Support\Themes\ThemeValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CoreStabilizationTest extends TestCase
{
    public function test_routes_are_split_without_changing_representative_names_or_urls(): void
    {
        $this->assertFileExists(base_path('routes/public.php'));
        $this->assertFileExists(base_path('routes/account.php'));
        $this->assertFileExists(base_path('routes/admin.php'));

        $this->assertSame('/', route('home', absolute: false));
        $this->assertSame('/ru/news', route('localized.news.index', ['locale' => 'ru'], false));
        $this->assertSame('/account', route('account', absolute: false));
        $this->assertSame('/admin/settings', route('admin.settings.general', absolute: false));
        $this->assertSame('/admin/settings/admin-panel', route('admin.settings.admin-panel', absolute: false));

        $adminLoginRoute = Route::getRoutes()->getByName('admin.login');
        $this->assertNotNull($adminLoginRoute);
        $this->assertSame(
            'admin(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?',
            $adminLoginRoute->wheres['adminPath'] ?? null,
        );

        $this->assertSame(
            GeneralSettingsController::class.'@general',
            Route::getRoutes()->getByName('admin.settings.general')?->getActionName(),
        );
        $this->assertSame(
            AdminPanelSettingsController::class.'@index',
            Route::getRoutes()->getByName('admin.settings.admin-panel')?->getActionName(),
        );
        $this->assertSame(
            SystemSettingsController::class.'@system',
            Route::getRoutes()->getByName('admin.settings.system')?->getActionName(),
        );
        $this->assertSame(
            MailSettingsController::class.'@mail',
            Route::getRoutes()->getByName('admin.settings.mail')?->getActionName(),
        );

        $this->assertContains(
            'throttle:30,1',
            Route::getRoutes()->getByName('password.reset')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:30,1',
            Route::getRoutes()->getByName('localized.password.reset')?->gatherMiddleware() ?? [],
        );

        $this->assertContains(
            'throttle:public-login',
            Route::getRoutes()->getByName('login.store')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-login',
            Route::getRoutes()->getByName('localized.login.store')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-registration',
            Route::getRoutes()->getByName('register.store')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-registration',
            Route::getRoutes()->getByName('localized.register.store')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-password-email',
            Route::getRoutes()->getByName('password.email')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-password-email',
            Route::getRoutes()->getByName('localized.password.email')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-password-reset',
            Route::getRoutes()->getByName('password.store')?->gatherMiddleware() ?? [],
        );
        $this->assertContains(
            'throttle:public-password-reset',
            Route::getRoutes()->getByName('localized.password.store')?->gatherMiddleware() ?? [],
        );

        $legacyDashboardRoute = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($route): bool => $route->uri() === '{adminPath}/dashboard');

        $this->assertNull($legacyDashboardRoute);
    }

    public function test_legacy_large_settings_controller_was_replaced_by_domain_controllers(): void
    {
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/SettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/AdminPanelSettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/GeneralSettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/SystemSettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/LanguageSettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/RegistrationSettingsController.php'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/MailSettingsController.php'));
    }

    public function test_content_and_mail_adapters_use_the_shared_safe_html_sanitizer(): void
    {
        $shared = app(SafeHtmlSanitizer::class);
        $page = app(PageHtmlSanitizer::class);
        $news = app(NewsHtmlSanitizer::class);
        $mail = app(CustomMailHtmlSanitizer::class);

        $pageHtml = '<p onclick="alert(1)">Text<script>alert(1)</script></p>'
            .'<img src="/uploads/pages/content/2026/07/abc-def.png" onerror="alert(1)">'
            .'<img src="https://example.test/unsafe.png">';
        $newsHtml = '<p data-align="center">News</p>'
            .'<img src="/uploads/news/content/2026/07/abc-def.webp">';
        $mailHtml = '<html><body><a href="javascript:alert(1)" onclick="alert(1)">Open</a>'
            .'<p style="color:red;behavior:url(x)">Mail</p><script>alert(1)</script></body></html>';

        $this->assertSame(
            $shared->sanitize($pageHtml, SafeHtmlSanitizer::PROFILE_PAGE),
            $page->sanitize($pageHtml),
        );
        $this->assertSame(
            $shared->sanitize($newsHtml, SafeHtmlSanitizer::PROFILE_NEWS),
            $news->sanitize($newsHtml),
        );
        $this->assertSame(
            $shared->sanitize($mailHtml, SafeHtmlSanitizer::PROFILE_EMAIL),
            $mail->sanitize($mailHtml),
        );

        $safePage = $page->sanitize($pageHtml);
        $this->assertStringContainsString('/uploads/pages/content/2026/07/abc-def.png', $safePage);
        $this->assertStringNotContainsString('script', $safePage);
        $this->assertStringNotContainsString('onclick', $safePage);
        $this->assertStringNotContainsString('https://example.test/unsafe.png', $safePage);

        $safeMail = $mail->sanitize($mailHtml);
        $this->assertStringNotContainsString('javascript:', $safeMail);
        $this->assertStringNotContainsString('onclick', $safeMail);
        $this->assertStringNotContainsString('<script', $safeMail);
        $this->assertStringNotContainsString('behavior:', $safeMail);
    }

    public function test_shared_theme_validator_rejects_missing_files_and_path_traversal(): void
    {
        $files = new Filesystem;
        $root = storage_path('framework/testing/theme-validator-'.bin2hex(random_bytes(5)));
        $themes = $root.'/themes';
        $publicThemes = $root.'/public-themes';
        $themePath = $themes.'/sample';
        $publicThemePath = $publicThemes.'/sample';

        $files->ensureDirectoryExists($themePath.'/views/layouts');
        $files->ensureDirectoryExists($publicThemePath.'/assets/images');
        $files->put($themePath.'/theme.json', json_encode([
            'name' => 'Sample',
            'slug' => 'sample',
            'version' => '1.0.0',
            'author' => 'KaevCMS',
            'preview' => '../outside.png',
        ], JSON_THROW_ON_ERROR));
        $files->put($themePath.'/views/layouts/app.blade.php', '<html></html>');

        try {
            $validator = app(ThemeValidator::class);
            $invalid = $validator->inspect(
                slug: 'sample',
                themesPath: $themes,
                publicThemesPath: $publicThemes,
                assetUrlPrefix: 'themes',
                activeTheme: 'default',
                requiredFiles: ['views/layouts/app.blade.php', 'views/home.blade.php'],
            );

            $this->assertFalse($invalid['valid']);
            $this->assertContains(__('Required file :file was not found.', ['file' => 'views/home.blade.php']), $invalid['errors']);
            $this->assertNull($invalid['preview_url']);

            $traversal = $validator->inspect(
                slug: '../sample',
                themesPath: $themes,
                publicThemesPath: $publicThemes,
                assetUrlPrefix: 'themes',
                activeTheme: 'default',
                requiredFiles: [],
            );

            $this->assertFalse($traversal['valid']);
            $this->assertContains(__('Invalid theme directory name.'), $traversal['errors']);
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
