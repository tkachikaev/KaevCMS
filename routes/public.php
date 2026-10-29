<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\SocialOAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Localization\LocaleController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\Payments\FreeKassaNotificationController;
use App\Http\Controllers\Payments\PayPalNotificationController;
use App\Http\Controllers\Payments\YooMoneyNotificationController;
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

    Route::get('/downloads', DownloadController::class)->name($namePrefix.'downloads');
    Route::get('/about', AboutController::class)->name($namePrefix.'about');

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
    ->middleware('throttle:20,1,language-switch')
    ->name('language.switch');

// Core-owned callback persists after Donations UI/module or new sales are
// disabled. Its trusted static guard is independent of database activation.
Route::post('/payments/yoomoney/notify', YooMoneyNotificationController::class)
    ->middleware('throttle:60,1,yoomoney-notify')->name('payments.yoomoney.notify');

// Stable callback endpoint reserved for FreeKassa. Iteration 2 is test-only;
// real money settlement remains gated inside the bundled driver.
Route::post('/payments/freekassa/notify', FreeKassaNotificationController::class)
    ->middleware('throttle:60,1,freekassa-notify')->name('payments.freekassa.notify');

// Core-owned, signature-verified and durable PayPal callback, independent of player checkout visibility.
Route::post('/payments/paypal/notify', PayPalNotificationController::class)
    ->middleware('throttle:60,1,paypal-notify')->name('payments.paypal.notify');

// Exact non-localized OAuth callbacks registered at Google and Yandex.
// These actions only use the website `web` guard; admin login remains separate.
foreach (['google', 'yandex', 'vk', 'telegram', 'discord'] as $provider) {
    Route::get('/auth/social/'.$provider, [SocialOAuthController::class, 'start'])
        ->middleware(['guest:web', 'throttle:15,1'])->defaults('social_provider', $provider)->name('social.'.$provider.'.start');
    Route::get('/auth/social/'.$provider.'/callback', [SocialOAuthController::class, 'callback'])
        ->middleware('throttle:30,1')->defaults('social_provider', $provider)->name('social.'.$provider.'.callback');
    Route::middleware(['guest:web', 'throttle:20,1'])->group(function () use ($provider): void {
        Route::get('/auth/social/'.$provider.'/register', [SocialOAuthController::class, 'registrationForm'])
            ->defaults('social_provider', $provider)->name('social.'.$provider.'.register');
        Route::post('/auth/social/'.$provider.'/register', [SocialOAuthController::class, 'register'])
            ->defaults('social_provider', $provider)->name('social.'.$provider.'.register.store');
        Route::post('/auth/social/'.$provider.'/email', [SocialOAuthController::class, 'sendVerification'])
            ->defaults('social_provider', $provider)->name('social.'.$provider.'.email.send');
        Route::get('/auth/social/'.$provider.'/email/verify', [SocialOAuthController::class, 'verifyEmail'])
            ->middleware('signed:relative')->defaults('social_provider', $provider)->name('social.'.$provider.'.email.verify');
    });
}
