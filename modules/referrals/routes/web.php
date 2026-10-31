<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Referrals\Http\Controllers\ReferralLinkController;

Route::post('/cancel', [ReferralLinkController::class, 'cancel'])
    ->middleware('throttle:20,1')
    ->name('cancel');
