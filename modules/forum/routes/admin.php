<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumSettingsController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumThemeController;

Route::get('/settings', [ForumSettingsController::class, 'edit'])->name('settings');
Route::put('/settings', [ForumSettingsController::class, 'update'])->name('settings.update');
Route::put('/settings/community', [ForumSettingsController::class, 'updateCommunity'])->name('community.update');
Route::post('/moderators', [ForumSettingsController::class, 'addModerator'])
    ->middleware('throttle:10,1')->name('moderators.store');
Route::delete('/moderators/{user}', [ForumSettingsController::class, 'removeModerator'])
    ->whereNumber('user')->middleware('throttle:10,1')->name('moderators.destroy');

Route::get('/themes', [ForumThemeController::class, 'index'])->name('themes');
Route::post('/themes/{theme}/activate', [ForumThemeController::class, 'activate'])
    ->where('theme', '[a-z0-9][a-z0-9_-]*')->middleware('throttle:20,1')->name('themes.activate');
