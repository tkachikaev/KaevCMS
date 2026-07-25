<?php

use App\Http\Controllers\Account\CharacterController;
use App\Http\Controllers\Account\GameAccountController;
use App\Http\Controllers\Account\GameAccountPasswordController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\RewardTransferController;
use App\Http\Controllers\Account\WebInventoryController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

$registerAccountRoutes = static function (bool $localized = false): void {
    $namePrefix = $localized ? 'localized.' : '';

    Route::middleware(['auth', 'site.active'])->group(function () use ($namePrefix): void {
        Route::get('/email/verify', EmailVerificationPromptController::class)->name($namePrefix.'verification.notice');
        Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name($namePrefix.'verification.verify');
        Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name($namePrefix.'verification.send');

        Route::middleware('site.verified')->group(function () use ($namePrefix): void {
            Route::get('/account', AccountController::class)->name($namePrefix.'account');
            Route::get('/account/profile', [ProfileController::class, 'edit'])
                ->name($namePrefix.'profile.edit');
            Route::get('/account/characters', CharacterController::class)
                ->name($namePrefix.'characters.index');
            Route::put('/account/profile/avatar', [ProfileController::class, 'updateAvatar'])
                ->middleware('throttle:20,1')
                ->name($namePrefix.'profile.avatar.update');
            Route::get('/account/game-accounts', [GameAccountController::class, 'index'])
                ->name($namePrefix.'game-accounts.index');
            Route::get('/account/game-accounts/create', [GameAccountController::class, 'create'])
                ->name($namePrefix.'game-accounts.create');
            Route::post('/account/game-accounts', [GameAccountController::class, 'store'])
                ->middleware('throttle:game-account-create')
                ->name($namePrefix.'game-accounts.store');
            Route::get('/account/game-accounts/{gameAccount}', [GameAccountController::class, 'show'])
                ->whereNumber('gameAccount')
                ->name($namePrefix.'game-accounts.show');
            Route::put('/account/game-accounts/{gameAccount}/password', [GameAccountPasswordController::class, 'update'])
                ->whereNumber('gameAccount')
                ->middleware('throttle:game-account-password')
                ->name($namePrefix.'game-accounts.password');
            Route::get('/account/web-inventory', WebInventoryController::class)
                ->name($namePrefix.'web-inventory.index');
            Route::post('/account/web-inventory/transfers', [RewardTransferController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name($namePrefix.'web-inventory.transfers.store');
        });

        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name($namePrefix.'logout');
    });
};

$registerAccountRoutes();

Route::prefix('{locale}')
    ->where(['locale' => (string) config('localization.locale_pattern')])
    ->group(static function () use ($registerAccountRoutes): void {
        $registerAccountRoutes(true);
    });
