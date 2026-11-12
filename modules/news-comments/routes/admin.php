<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\NewsComments\Http\Controllers\AdminNewsCommentController;

Route::get('/', [AdminNewsCommentController::class, 'index'])->name('index');
Route::put('/settings', [AdminNewsCommentController::class, 'updateSettings'])->name('settings.update');
Route::put('/news/{news}', [AdminNewsCommentController::class, 'updateNews'])
    ->whereNumber('news')
    ->name('news.update');
