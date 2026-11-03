<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Shop\Http\Controllers\AdminShopController;
use KaevCMS\Modules\Shop\Http\Controllers\AdminShopProductController;
use KaevCMS\Modules\Shop\Http\Controllers\AdminShopPurchaseController;

Route::get('/', [AdminShopController::class, 'index'])->name('index');
Route::put('/settings', [AdminShopController::class, 'updateSettings'])->name('settings.update');
Route::put('/servers/{server}/settings', [AdminShopController::class, 'updateServerSettings'])->whereNumber('server')->name('servers.settings.update');
Route::get('/purchases', [AdminShopPurchaseController::class, 'index'])->name('purchases.index');
Route::get('/purchases/{purchase}', [AdminShopPurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');
Route::get('/servers/{server}', [AdminShopController::class, 'server'])->whereNumber('server')->name('servers.show');
Route::post('/servers/{server}/tabs', [AdminShopController::class, 'storeTab'])->whereNumber('server')->name('tabs.store');
Route::get('/servers/{server}/tabs/{tab}/edit', [AdminShopController::class, 'editTab'])->whereNumber(['server', 'tab'])->name('tabs.edit');
Route::put('/servers/{server}/tabs/{tab}', [AdminShopController::class, 'updateTab'])->whereNumber(['server', 'tab'])->name('tabs.update');
Route::delete('/servers/{server}/tabs/{tab}', [AdminShopController::class, 'destroyTab'])->whereNumber(['server', 'tab'])->name('tabs.destroy');
Route::post('/servers/{server}/categories', [AdminShopController::class, 'storeCategory'])->whereNumber('server')->name('categories.store');
Route::get('/servers/{server}/categories/{category}/edit', [AdminShopController::class, 'editCategory'])->whereNumber(['server', 'category'])->name('categories.edit');
Route::put('/servers/{server}/categories/{category}', [AdminShopController::class, 'updateCategory'])->whereNumber(['server', 'category'])->name('categories.update');
Route::delete('/servers/{server}/categories/{category}', [AdminShopController::class, 'destroyCategory'])->whereNumber(['server', 'category'])->name('categories.destroy');

Route::get('/servers/{server}/products/create', [AdminShopProductController::class, 'create'])->whereNumber('server')->name('products.create');
Route::post('/servers/{server}/products', [AdminShopProductController::class, 'store'])->whereNumber('server')->name('products.store');
Route::get('/servers/{server}/products/{product}/edit', [AdminShopProductController::class, 'edit'])->whereNumber(['server', 'product'])->name('products.edit');
Route::put('/servers/{server}/products/{product}', [AdminShopProductController::class, 'update'])->whereNumber(['server', 'product'])->name('products.update');
Route::delete('/servers/{server}/products/{product}', [AdminShopProductController::class, 'destroy'])->whereNumber(['server', 'product'])->name('products.destroy');
Route::post('/servers/{server}/products/{product}/bundle-items', [AdminShopProductController::class, 'addBundleItem'])->whereNumber(['server', 'product'])->name('bundle-items.store');
Route::delete('/servers/{server}/products/{product}/bundle-items/{bundleItem}', [AdminShopProductController::class, 'removeBundleItem'])->whereNumber(['server', 'product', 'bundleItem'])->name('bundle-items.destroy');

// Catalog editor (1.0.6): direct sections, ordering, quick visibility and item lookup.
Route::post('/servers/{server}/sections', [AdminShopController::class, 'storeSection'])->whereNumber('server')->name('sections.store');
Route::put('/servers/{server}/sections/order', [AdminShopController::class, 'orderSections'])->whereNumber('server')->name('sections.order');
Route::put('/servers/{server}/sections/{tab}', [AdminShopController::class, 'updateSection'])->whereNumber(['server', 'tab'])->name('sections.update');
Route::patch('/servers/{server}/sections/{tab}/visibility', [AdminShopController::class, 'sectionVisibility'])->whereNumber(['server', 'tab'])->name('sections.visibility');
Route::put('/servers/{server}/sections/{tab}/products/order', [AdminShopController::class, 'orderProducts'])->whereNumber(['server', 'tab'])->name('sections.products.order');
Route::get('/servers/{server}/items', [AdminShopController::class, 'itemSearch'])->whereNumber('server')->middleware('throttle:120,1')->name('items.search');
Route::get('/servers/{server}/items/{item}', [AdminShopController::class, 'itemPreview'])->whereNumber(['server', 'item'])->middleware('throttle:120,1')->name('items.preview');
