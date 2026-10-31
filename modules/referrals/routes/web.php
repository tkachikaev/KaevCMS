<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Referrals\Http\Controllers\ReferralAccountController;
use KaevCMS\Modules\Referrals\Http\Controllers\ReferralLinkController;
use KaevCMS\Modules\Referrals\Http\Controllers\ReferralProgressController;

Route::get('/', [ReferralAccountController::class, 'index'])
    ->middleware(['auth:web', 'site.active', 'site.verified'])
    ->name('index');

Route::post('/cancel', [ReferralLinkController::class, 'cancel'])
    ->middleware('throttle:20,1')
    ->name('cancel');

Route::post('/check/{invite}/{server}', [ReferralProgressController::class, 'check'])
    ->middleware(['auth:web', 'throttle:3,5'])
    ->whereNumber(['invite', 'server'])
    ->name('check');
