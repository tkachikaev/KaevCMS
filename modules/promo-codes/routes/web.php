<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\PromoCodes\Http\Controllers\PromoCodeController;

Route::middleware(['auth', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', [PromoCodeController::class, 'index'])->name('index');
    Route::post('/activate', [PromoCodeController::class, 'activate'])
        ->middleware('throttle:5,1')
        ->name('activate');
});
