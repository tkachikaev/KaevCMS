<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\SupportTickets\Http\Controllers\SupportTicketController;

Route::middleware(['auth', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', [SupportTicketController::class, 'index'])->name('index');
    Route::post('/', [SupportTicketController::class, 'store'])->middleware('throttle:3,10')->name('store');
    Route::get('/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket')->name('show');
    Route::post('/{ticket}/reply', [SupportTicketController::class, 'reply'])
        ->whereNumber('ticket')
        ->middleware('throttle:10,1')
        ->name('reply');
    Route::patch('/{ticket}/close', [SupportTicketController::class, 'close'])
        ->whereNumber('ticket')
        ->middleware('throttle:5,1')
        ->name('close');
});
