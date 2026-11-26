<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Voting\Http\Controllers\VotingAccountController;

Route::middleware(['auth', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', [VotingAccountController::class, 'index'])->name('index');
    Route::post('/invite', [VotingAccountController::class, 'invite'])->middleware('throttle:5,1')->name('invite');
});
