<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationController;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationGameExchangeController;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationProviderController;

Route::get('/', [AdminDonationController::class, 'index'])->name('index');
Route::get('/exchange', [AdminDonationGameExchangeController::class, 'index'])->name('exchange.index');
Route::put('/exchange', [AdminDonationGameExchangeController::class, 'save'])->name('exchange.save');
Route::get('/exchange/{serverId}/items/{itemId}', [AdminDonationGameExchangeController::class, 'itemPreview'])
    ->whereNumber('serverId')->where('itemId', '[0-9]{1,10}')->middleware('throttle:120,1,donations-item-preview')->name('exchange.items.preview');
Route::put('/exchange/{serverId}', [AdminDonationGameExchangeController::class, 'update'])->whereNumber('serverId')->name('exchange.update');
Route::put('/workspace', [AdminDonationController::class, 'saveWorkspace'])->name('workspace.update');
Route::put('/settings', [AdminDonationController::class, 'update'])->name('settings.update');
Route::put('/limits', [AdminDonationController::class, 'limits'])->name('limits.update');
Route::post('/rates', [AdminDonationController::class, 'rate'])->name('rates.store');
Route::get('/orders', [AdminDonationController::class, 'orders'])->name('orders.index');
Route::post('/orders/{orderUuid}/reviews', [AdminDonationController::class, 'reportReview'])->whereUuid('orderUuid')->name('orders.reviews.store');
Route::get('/orders/{orderUuid}', [AdminDonationController::class, 'showOrder'])->whereUuid('orderUuid')->name('orders.show');
Route::get('/providers', [AdminDonationProviderController::class, 'index'])->name('providers.index');
Route::get('/providers/{providerCode}', [AdminDonationProviderController::class, 'show'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.show');
Route::put('/providers/activation', [AdminDonationProviderController::class, 'updateActivation'])->name('providers.activation.update');
Route::put('/providers/{providerCode}', [AdminDonationProviderController::class, 'update'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.update');
