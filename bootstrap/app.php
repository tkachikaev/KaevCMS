<?php

use App\Http\Middleware\AdminSecurityHeaders;
use App\Http\Middleware\AuthenticatePlayerSession;
use App\Http\Middleware\EnforceAdminAccess;
use App\Http\Middleware\EnsureAdminPath;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\RedirectAuthenticatedAdmin;
use App\Http\Middleware\RequireActiveSiteUser;
use App\Http\Middleware\RequireAdminAuthentication;
use App\Http\Middleware\RequireConfiguredEmailVerification;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetApplicationLocale;
use App\Http\Middleware\TrustProxies;
use App\Support\FrameworkCache;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\TrustProxies as LaravelTrustProxies;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(LaravelTrustProxies::class, TrustProxies::class);

        $middleware->validateCsrfTokens(except: ['payments/yoomoney/notify']);
        // The maintenance bypass cookie is read before cookie decryption and carries its own
        // HMAC, exactly as Laravel issues it; the updater sets it for the finalisation step.
        $middleware->encryptCookies(except: ['laravel_maintenance']);
        $middleware->appendToGroup('web', AuthenticatePlayerSession::class);
        $middleware->appendToGroup('web', SetApplicationLocale::class);
        $middleware->appendToGroup('web', SecurityHeaders::class);
        $middleware->alias([
            'admin.auth' => RequireAdminAuthentication::class,
            'admin.access' => EnforceAdminAccess::class,
            'admin.path' => EnsureAdminPath::class,
            'admin.guest' => RedirectAuthenticatedAdmin::class,
            'admin.headers' => AdminSecurityHeaders::class,
            'module.enabled' => EnsureModuleEnabled::class,
            'site.active' => RequireActiveSiteUser::class,
            'site.verified' => RequireConfiguredEmailVerification::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An update package above post_max_size arrives as an empty request before the session
        // starts; return to the updater with an explanation instead of a bare HTTP 413 page.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            // Routing has not run yet; the admin path is configurable, so match the path itself.
            if (! $request->isMethod('POST') || preg_match('#\Aadmin(?:-[a-z0-9]+(?:-[a-z0-9]+)*)?/settings/system/updates\z#', $request->path()) !== 1) {
                return null;
            }

            // GET on the same address is the updates page.
            return redirect($request->url().'?upload=too-large');
        });

        $exceptions->dontFlash([
            'database_password',
            'game_password',
            'game_password_confirmation',
            'current_password',
            'password',
            'password_confirmation',
        ]);
    })->create();

FrameworkCache::discardStale($application);

$publicPathConfig = __DIR__.'/kaevcms-public-path.php';
if (is_file($publicPathConfig)) {
    $configuredPublicPath = require $publicPathConfig;
    if (is_string($configuredPublicPath) && $configuredPublicPath !== '') {
        $application->usePublicPath($configuredPublicPath);
    }
}

return $application;
