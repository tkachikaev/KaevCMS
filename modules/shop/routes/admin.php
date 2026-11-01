<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Shop\Http\Controllers\AdminShopController;

Route::get('/', [AdminShopController::class, 'index'])->name('index');
Route::get('/servers/{server}', [AdminShopController::class, 'server'])->whereNumber('server')->name('servers.show');
Route::post('/servers/{server}/tabs', [AdminShopController::class, 'storeTab'])->whereNumber('server')->name('tabs.store');
Route::get('/servers/{server}/tabs/{tab}/edit', [AdminShopController::class, 'editTab'])->whereNumber(['server', 'tab'])->name('tabs.edit');
Route::put('/servers/{server}/tabs/{tab}', [AdminShopController::class, 'updateTab'])->whereNumber(['server', 'tab'])->name('tabs.update');
Route::delete('/servers/{server}/tabs/{tab}', [AdminShopController::class, 'destroyTab'])->whereNumber(['server', 'tab'])->name('tabs.destroy');
Route::post('/servers/{server}/categories', [AdminShopController::class, 'storeCategory'])->whereNumber('server')->name('categories.store');
Route::get('/servers/{server}/categories/{category}/edit', [AdminShopController::class, 'editCategory'])->whereNumber(['server', 'category'])->name('categories.edit');
Route::put('/servers/{server}/categories/{category}', [AdminShopController::class, 'updateCategory'])->whereNumber(['server', 'category'])->name('categories.update');
Route::delete('/servers/{server}/categories/{category}', [AdminShopController::class, 'destroyCategory'])->whereNumber(['server', 'category'])->name('categories.destroy');
