<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\PromoCodes\Http\Controllers\AdminPromoCodeActivationController;
use KaevCMS\Modules\PromoCodes\Http\Controllers\AdminPromoCodeController;

Route::get('/', [AdminPromoCodeController::class, 'index'])->name('index');
Route::get('/activations', AdminPromoCodeActivationController::class)->name('activations');
Route::get('/create', [AdminPromoCodeController::class, 'create'])->name('create');
Route::post('/', [AdminPromoCodeController::class, 'store'])->name('store');
Route::get('/{promoCode}/edit', [AdminPromoCodeController::class, 'edit'])->whereNumber('promoCode')->name('edit');
Route::put('/{promoCode}', [AdminPromoCodeController::class, 'update'])->whereNumber('promoCode')->name('update');
Route::patch('/{promoCode}/toggle', [AdminPromoCodeController::class, 'toggle'])->whereNumber('promoCode')->name('toggle');
Route::delete('/{promoCode}', [AdminPromoCodeController::class, 'destroy'])->whereNumber('promoCode')->name('destroy');
