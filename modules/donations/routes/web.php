<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Donations\Http\Controllers\DonationController;

Route::middleware(['auth', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', DonationController::class)->name('index');
    Route::post('/top-up', [DonationController::class, 'topUp'])->middleware('throttle:10,1,donations-create')->name('topup');
    Route::get('/orders', [DonationController::class, 'orders'])->name('orders.index');
    Route::get('/orders/{orderUuid}', [DonationController::class, 'show'])->whereUuid('orderUuid')->name('orders.show');
    Route::post('/orders', [DonationController::class, 'store'])->middleware('throttle:10,1,donations-create')->name('orders.store');
    Route::get('/orders/{orderUuid}/checkout', [DonationController::class, 'checkout'])->whereUuid('orderUuid')->middleware('throttle:30,1,donations-checkout')->name('orders.checkout');
    Route::post('/orders/{orderUuid}/prepare', [DonationController::class, 'prepare'])->whereUuid('orderUuid')->middleware('throttle:10,1,donations-prepare')->name('orders.prepare');
    Route::post('/orders/{orderUuid}/cancel', [DonationController::class, 'cancel'])->whereUuid('orderUuid')->middleware('throttle:10,1,donations-cancel')->name('orders.cancel');
});
