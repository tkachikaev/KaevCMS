<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Voting\Http\Controllers\AdminVotingController;
use KaevCMS\Modules\Voting\Http\Controllers\AdminVotingRewardController;

Route::get('/', [AdminVotingController::class, 'index'])->name('index');
Route::get('/providers/ktreon', [AdminVotingController::class, 'provider'])->name('providers.ktreon.show');
Route::put('/providers/ktreon', [AdminVotingController::class, 'save'])->name('providers.ktreon.save');
Route::post('/providers/ktreon/check', [AdminVotingController::class, 'check'])
    ->middleware('throttle:6,1,voting-check')->name('providers.ktreon.check');
Route::get('/rewards', [AdminVotingRewardController::class, 'index'])->name('rewards.index');
Route::put('/rewards/{server}', [AdminVotingRewardController::class, 'update'])->whereNumber('server')->name('rewards.update');
Route::get('/rewards/{server}/items/{item}', [AdminVotingRewardController::class, 'preview'])
    ->whereNumber(['server', 'item'])->middleware('throttle:120,1,voting-item-preview')->name('rewards.preview');
Route::get('/history', [AdminVotingController::class, 'history'])->name('history.index');
