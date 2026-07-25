<?php

use App\Http\Middleware\AdminSecurityHeaders;
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
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as LaravelTrustProxies;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(LaravelTrustProxies::class, TrustProxies::class);

        $middleware->validateCsrfTokens(except: []);
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
        $exceptions->dontFlash([
            'database_password',
            'game_password',
            'game_password_confirmation',
            'current_password',
        ]);
    })->create();

$publicPathConfig = __DIR__.'/kaevcms-public-path.php';
if (is_file($publicPathConfig)) {
    $configuredPublicPath = require $publicPathConfig;
    if (is_string($configuredPublicPath) && $configuredPublicPath !== '') {
        $application->usePublicPath($configuredPublicPath);
    }
}

return $application;
