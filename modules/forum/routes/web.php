<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumController;

Route::get('/', [ForumController::class, 'index'])->name('index');
Route::post('/author', [ForumController::class, 'chooseAuthor'])
    ->middleware('throttle:10,1')
    ->name('author.choose');
