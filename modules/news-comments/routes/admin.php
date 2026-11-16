<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\NewsComments\Http\Controllers\AdminNewsCommentController;

Route::get('/', [AdminNewsCommentController::class, 'index'])->name('index');
Route::put('/settings', [AdminNewsCommentController::class, 'updateSettings'])->name('settings.update');
Route::put('/news/{news}', [AdminNewsCommentController::class, 'updateNews'])
    ->whereNumber('news')
    ->name('news.update');

Route::post('/comments/{comment}/approve', [AdminNewsCommentController::class, 'approve'])
    ->whereNumber('comment')->name('comments.approve');
Route::post('/comments/{comment}/reject', [AdminNewsCommentController::class, 'reject'])
    ->whereNumber('comment')->name('comments.reject');
Route::post('/comments/{comment}/approve-edit', [AdminNewsCommentController::class, 'approveEdit'])
    ->whereNumber('comment')->name('comments.approve-edit');
Route::post('/comments/{comment}/reject-edit', [AdminNewsCommentController::class, 'rejectEdit'])
    ->whereNumber('comment')->name('comments.reject-edit');
Route::post('/comments/{comment}/hide', [AdminNewsCommentController::class, 'hide'])
    ->whereNumber('comment')->name('comments.hide');
Route::post('/comments/{comment}/restore', [AdminNewsCommentController::class, 'restore'])
    ->whereNumber('comment')->name('comments.restore');
Route::delete('/comments/{comment}', [AdminNewsCommentController::class, 'destroy'])
    ->whereNumber('comment')->name('comments.destroy');
