<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\DailyRewards\Http\Controllers\DailyRewardController;

Route::middleware(['auth', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', [DailyRewardController::class, 'index'])->name('index');
    Route::post('/claim', [DailyRewardController::class, 'claim'])
        ->middleware('throttle:5,1')
        ->name('claim');
});
