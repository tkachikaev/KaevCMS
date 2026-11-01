<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Shop\Http\Controllers\AdminShopController;

Route::get('/', [AdminShopController::class, 'index'])->name('index');
