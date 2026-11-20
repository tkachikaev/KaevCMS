<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumModerationController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumSearchController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumPostController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumStructureController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumTopicController;

Route::get('/', [ForumController::class, 'index'])->name('index');
Route::get('/search', ForumSearchController::class)
    ->middleware('throttle:30,1')->name('search');
Route::post('/author', [ForumController::class, 'chooseAuthor'])
    ->middleware('throttle:10,1')->name('author.choose');

// All structure mutations independently check the valid Owner actor in the controller.
Route::post('/categories', [ForumStructureController::class, 'createCategory'])
    ->middleware('throttle:20,1')->name('categories.create');
Route::put('/categories/{category}', [ForumStructureController::class, 'updateCategory'])->name('categories.update');
Route::delete('/categories/{category}', [ForumStructureController::class, 'deleteCategory'])->name('categories.delete');
Route::post('/categories/{category}/move', [ForumStructureController::class, 'moveCategory'])->name('categories.move');

Route::post('/categories/{category}/sections', [ForumStructureController::class, 'createSection'])
    ->middleware('throttle:20,1')->name('sections.create');
Route::put('/sections/{section}', [ForumStructureController::class, 'updateSection'])->name('sections.update');
Route::delete('/sections/{section}', [ForumStructureController::class, 'deleteSection'])->name('sections.delete');
Route::post('/sections/{section}/move', [ForumStructureController::class, 'moveSection'])->name('sections.move');
Route::get('/sections/{section}', [ForumTopicController::class, 'section'])
    ->whereNumber('section')->name('sections.show');
Route::get('/sections/{section}/moderation', [ForumModerationController::class, 'index'])
    ->whereNumber('section')->middleware('throttle:30,1')->name('sections.moderation');
Route::get('/sections/{section}/topics/new', [ForumTopicController::class, 'create'])
    ->whereNumber('section')->name('topics.create');
Route::post('/sections/{section}/topics', [ForumTopicController::class, 'store'])
    ->whereNumber('section')->middleware('throttle:5,1')->name('topics.store');
Route::get('/topics/{topic}', [ForumTopicController::class, 'show'])
    ->whereNumber('topic')->name('topics.show');
Route::post('/topics/{topic}/moderate/{action}', [ForumModerationController::class, 'action'])
    ->whereNumber('topic')
    ->where('action', 'close|reopen|pin|unpin|hide|unhide|delete|restore')
    ->middleware('throttle:20,1')->name('topics.moderate');
Route::post('/topics/{topic}/move', [ForumModerationController::class, 'move'])
    ->whereNumber('topic')->middleware('throttle:20,1')->name('topics.move');
Route::post('/topics/{topic}/replies', [ForumTopicController::class, 'reply'])
    ->whereNumber('topic')->middleware('throttle:10,1')->name('topics.reply');

Route::get('/posts/{post}/edit', [ForumPostController::class, 'edit'])
    ->whereNumber('post')->name('posts.edit');
Route::patch('/posts/{post}', [ForumPostController::class, 'update'])
    ->whereNumber('post')->middleware('throttle:20,1')->name('posts.update');
Route::delete('/posts/{post}', [ForumPostController::class, 'destroy'])
    ->whereNumber('post')->middleware('throttle:10,1')->name('posts.destroy');
Route::get('/posts/{post}/history', [ForumPostController::class, 'history'])
    ->whereNumber('post')->middleware('throttle:30,1')->name('posts.history');

Route::get('/trash', [ForumStructureController::class, 'trash'])->name('trash');
Route::post('/trash/categories/{category}', [ForumStructureController::class, 'restoreCategory'])
    ->whereNumber('category')->name('categories.restore');
Route::post('/trash/sections/{section}', [ForumStructureController::class, 'restoreSection'])
    ->whereNumber('section')->name('sections.restore');
