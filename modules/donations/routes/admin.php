<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Donations\Http\Controllers\AdminDonationController;

Route::get('/', [AdminDonationController::class, 'index'])->name('index');
Route::put('/settings', [AdminDonationController::class, 'update'])->name('settings.update');
Route::put('/limits', [AdminDonationController::class, 'limits'])->name('limits.update');
Route::post('/rates', [AdminDonationController::class, 'rate'])->name('rates.store');
Route::get('/orders', [AdminDonationController::class, 'orders'])->name('orders.index');
Route::get('/orders/{orderUuid}', [AdminDonationController::class, 'showOrder'])->whereUuid('orderUuid')->name('orders.show');
Route::get('/providers', [\KaevCMS\Modules\Donations\Http\Controllers\AdminDonationProviderController::class, 'index'])->name('providers.index');
Route::get('/providers/{providerCode}', [\KaevCMS\Modules\Donations\Http\Controllers\AdminDonationProviderController::class, 'show'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.show');
Route::put('/providers/{providerCode}', [\KaevCMS\Modules\Donations\Http\Controllers\AdminDonationProviderController::class, 'update'])->where('providerCode', '[a-z][a-z0-9_-]{1,39}')->name('providers.update');
