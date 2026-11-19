<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumSettingsController;

Route::get('/settings', ForumSettingsController::class)->name('settings');
