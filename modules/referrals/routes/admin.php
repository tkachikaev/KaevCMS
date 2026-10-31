<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Referrals\Http\Controllers\AdminReferralController;

Route::get('/', AdminReferralController::class)->name('index');
