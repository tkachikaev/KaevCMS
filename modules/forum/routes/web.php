<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Forum\Http\Controllers\ForumController;
use KaevCMS\Modules\Forum\Http\Controllers\ForumStructureController;

Route::get('/', [ForumController::class, 'index'])->name('index');
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
Route::get('/sections/{section}', [ForumStructureController::class, 'section'])->name('sections.show');

Route::get('/trash', [ForumStructureController::class, 'trash'])->name('trash');
Route::post('/trash/categories/{category}', [ForumStructureController::class, 'restoreCategory'])
    ->whereNumber('category')->name('categories.restore');
Route::post('/trash/sections/{section}', [ForumStructureController::class, 'restoreSection'])
    ->whereNumber('section')->name('sections.restore');
