<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Voting\Http\Controllers\AdminVotingController;

Route::get('/', [AdminVotingController::class, 'index'])->name('index');
Route::get('/providers/ktreon', [AdminVotingController::class, 'provider'])->name('providers.ktreon.show');
Route::put('/providers/ktreon', [AdminVotingController::class, 'save'])->name('providers.ktreon.save');
Route::post('/providers/ktreon/check', [AdminVotingController::class, 'check'])
    ->middleware('throttle:6,1,voting-check')->name('providers.ktreon.check');
Route::get('/rewards', [AdminVotingController::class, 'rewards'])->name('rewards.index');
Route::get('/history', [AdminVotingController::class, 'history'])->name('history.index');
