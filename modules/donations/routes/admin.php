<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationController;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationGameExchangeController;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationProviderController;
use KaevCMS\Modules\Donations\Http\Controllers\AdminPayPalController;

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
Route::post('/orders/{orderUuid}/freekassa-recovery', [AdminDonationController::class, 'recoverFreeKassa'])
    ->whereUuid('orderUuid')->middleware('throttle:5,1,donations-freekassa-recovery')->name('orders.freekassa.recovery');
Route::post('/orders/{orderUuid}/reviews', [AdminDonationController::class, 'reportReview'])->whereUuid('orderUuid')->name('orders.reviews.store');
Route::get('/orders/{orderUuid}', [AdminDonationController::class, 'showOrder'])->whereUuid('orderUuid')->name('orders.show');
Route::get('/providers', [AdminDonationProviderController::class, 'index'])->name('providers.index');

// PayPal has dedicated Sandbox/Live settings and an independently verified player checkout.
Route::get('/providers/paypal', [AdminPayPalController::class, 'show'])->name('providers.paypal.show');
Route::put('/providers/paypal', [AdminPayPalController::class, 'save'])->name('providers.paypal.save');
Route::post('/providers/paypal/check', [AdminPayPalController::class, 'check'])->middleware('throttle:5,1,paypal-check')->name('providers.paypal.check');
Route::post('/providers/paypal/test-purchase', [AdminPayPalController::class, 'start'])->middleware('throttle:5,1,paypal-sandbox')->name('providers.paypal.test.start');
Route::get('/providers/paypal/test/{attemptId}/return', [AdminPayPalController::class, 'returned'])->whereNumber('attemptId')->name('providers.paypal.test.return');
Route::get('/providers/paypal/test/{attemptId}/cancel', [AdminPayPalController::class, 'cancelled'])->whereNumber('attemptId')->name('providers.paypal.test.cancel');
Route::post('/providers/paypal/test/{attemptId}/capture', [AdminPayPalController::class, 'capture'])->whereNumber('attemptId')->middleware('throttle:5,1,paypal-capture')->name('providers.paypal.test.capture');

Route::post('/providers/yoomoney/notification-test', [AdminDonationProviderController::class, 'startNotificationTest'])
    ->middleware('throttle:10,1,donations-notification-test')->name('providers.notification-test.start');
Route::get('/providers/yoomoney/notification-test/{token}', [AdminDonationProviderController::class, 'notificationTestStatus'])
    ->where('token', '[A-Za-z0-9]{40}')->name('providers.notification-test.status');
Route::post('/providers/freekassa/test-purchase', [AdminDonationProviderController::class, 'startFreeKassaTestPurchase'])
    ->middleware('throttle:5,1,donations-freekassa-test')->name('providers.freekassa.test-purchase');
Route::get('/providers/{providerCode}', [AdminDonationProviderController::class, 'show'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.show');
Route::put('/providers/activation', [AdminDonationProviderController::class, 'updateActivation'])->name('providers.activation.update');
Route::put('/providers/{providerCode}', [AdminDonationProviderController::class, 'update'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.update');
