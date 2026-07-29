<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\SupportTickets\Http\Controllers\AdminSupportTicketController;
use KaevCMS\Modules\SupportTickets\Http\Controllers\AdminSupportTicketSettingsController;

Route::get('/', [AdminSupportTicketController::class, 'index'])->name('index');
Route::get('/settings', [AdminSupportTicketSettingsController::class, 'edit'])->name('settings');
Route::put('/settings', [AdminSupportTicketSettingsController::class, 'update'])->name('settings.update');
Route::get('/{ticket}', [AdminSupportTicketController::class, 'show'])->whereNumber('ticket')->name('show');
Route::patch('/{ticket}/assign', [AdminSupportTicketController::class, 'assign'])->whereNumber('ticket')->name('assign');
Route::post('/{ticket}/reply', [AdminSupportTicketController::class, 'reply'])
    ->whereNumber('ticket')
    ->middleware('throttle:30,1')
    ->name('reply');
Route::post('/{ticket}/note', [AdminSupportTicketController::class, 'note'])
    ->whereNumber('ticket')
    ->middleware('throttle:30,1')
    ->name('note');
Route::patch('/{ticket}/close', [AdminSupportTicketController::class, 'close'])->whereNumber('ticket')->name('close');
Route::patch('/{ticket}/reopen', [AdminSupportTicketController::class, 'reopen'])->whereNumber('ticket')->name('reopen');
Route::put('/{ticket}/messages/{message}', [AdminSupportTicketController::class, 'editMessage'])
    ->whereNumber(['ticket', 'message'])
    ->name('messages.update');
