<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\DailyRewards\Http\Controllers\AdminDailyRewardClaimController;
use KaevCMS\Modules\DailyRewards\Http\Controllers\AdminDailyRewardController;

Route::get('/', [AdminDailyRewardController::class, 'index'])->name('index');
Route::get('/claims', AdminDailyRewardClaimController::class)->name('claims');
Route::get('/create', [AdminDailyRewardController::class, 'create'])->name('create');
Route::post('/', [AdminDailyRewardController::class, 'store'])->name('store');
Route::get('/{calendar}/edit', [AdminDailyRewardController::class, 'edit'])->whereNumber('calendar')->name('edit');
Route::get('/{calendar}/items/{item}', [AdminDailyRewardController::class, 'itemPreview'])
    ->whereNumber(['calendar', 'item'])
    ->middleware('throttle:60,1')
    ->name('items.preview');
Route::put('/{calendar}', [AdminDailyRewardController::class, 'update'])->whereNumber('calendar')->name('update');
Route::patch('/{calendar}/toggle', [AdminDailyRewardController::class, 'toggle'])->whereNumber('calendar')->name('toggle');
