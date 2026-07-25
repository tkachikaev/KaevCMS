<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Localization\LocaleController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ServerMonitorStatusController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

$registerPublicRoutes = static function (bool $localized = false): void {
    $namePrefix = $localized ? 'localized.' : '';

    Route::get('/', HomeController::class)->name($namePrefix.'home');
    Route::post('/server-status/refresh', ServerMonitorStatusController::class)
        ->middleware('throttle:120,1')
        ->name($namePrefix.'server-monitor.refresh');
    Route::get('/news', [NewsController::class, 'index'])->name($namePrefix.'news.index');
    Route::get('/statistics', [StatisticsController::class, $localized ? 'indexLocalized' : 'index'])
        ->middleware('throttle:120,1')
        ->name($namePrefix.'statistics.index');
    Route::get('/statistics/{gameServer}', [StatisticsController::class, $localized ? 'showLocalized' : 'show'])
        ->whereNumber('gameServer')
        ->middleware('throttle:120,1')
        ->name($namePrefix.'statistics.show');

    if ($localized) {
        Route::get('/news/{slug}', [NewsController::class, 'showLocalized'])
            ->where('slug', '[^/]+')
            ->name($namePrefix.'news.show');
        Route::get('/pages/{slug}', [PageController::class, 'showLocalized'])
            ->where('slug', '[^/]+')
            ->name($namePrefix.'pages.show');
    } else {
        Route::get('/news/{news:slug}', [NewsController::class, 'show'])->name($namePrefix.'news.show');
        Route::get('/pages/{page:slug}', [PageController::class, 'show'])->name($namePrefix.'pages.show');
    }

    Route::view('/downloads', 'theme::pages.downloads')->name($namePrefix.'downloads');
    Route::view('/about', 'theme::pages.about')->name($namePrefix.'about');

    Route::middleware('guest')->group(function () use ($namePrefix): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name($namePrefix.'login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:public-login')
            ->name($namePrefix.'login.store');

        Route::get('/register', [RegisteredUserController::class, 'create'])->name($namePrefix.'register');
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('throttle:public-registration')
            ->name($namePrefix.'register.store');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name($namePrefix.'password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:public-password-email')
            ->name($namePrefix.'password.email');

        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
            ->middleware('throttle:30,1')
            ->name($namePrefix.'password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:public-password-reset')
            ->name($namePrefix.'password.store');
    });
};

$registerPublicRoutes();

Route::prefix('{locale}')
    ->where(['locale' => (string) config('localization.locale_pattern')])
    ->group(static function () use ($registerPublicRoutes): void {
        $registerPublicRoutes(true);
    });

Route::get('/language/{locale}', [LocaleController::class, 'public'])
    ->where('locale', (string) config('localization.locale_pattern'))
    ->middleware('throttle:20,1')
    ->name('language.switch');
