<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Shop\Http\Controllers\ShopAccountController;

Route::middleware(['auth:web', 'site.active', 'site.verified'])->group(function (): void {
    Route::get('/', [ShopAccountController::class, 'index'])->name('index');
    Route::post('/checkout', [ShopAccountController::class, 'store'])
        ->middleware('throttle:10,1')->name('checkout');
});
