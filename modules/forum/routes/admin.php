<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumSettingsController;

Route::get('/settings', [ForumSettingsController::class, 'edit'])->name('settings');
Route::put('/settings', [ForumSettingsController::class, 'update'])->name('settings.update');
Route::post('/moderators', [ForumSettingsController::class, 'addModerator'])
    ->middleware('throttle:10,1')->name('moderators.store');
Route::delete('/moderators/{user}', [ForumSettingsController::class, 'removeModerator'])
    ->whereNumber('user')->middleware('throttle:10,1')->name('moderators.destroy');
