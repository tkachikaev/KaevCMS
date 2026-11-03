<?php

use Illuminate\Support\Facades\Route;
use KaevCMS\Modules\Referrals\Http\Controllers\AdminReferralController;

Route::get('/', [AdminReferralController::class, 'index'])->name('index');
Route::put('/settings', [AdminReferralController::class, 'updateSettings'])->name('settings.update');
Route::get('/servers/{server}', [AdminReferralController::class, 'server'])->whereNumber('server')->name('servers.show');
Route::put('/servers/{server}', [AdminReferralController::class, 'updateServer'])->whereNumber('server')->name('servers.update');
Route::post('/servers/{server}/rules', [AdminReferralController::class, 'storeRule'])->whereNumber('server')->name('rules.store');
Route::delete('/servers/{server}/rules/{rule}', [AdminReferralController::class, 'destroyRule'])
    ->whereNumber(['server', 'rule'])->name('rules.destroy');
Route::get('/invites', [AdminReferralController::class, 'invites'])->name('invites.index');
Route::patch('/invites/{invite}/freeze', [AdminReferralController::class, 'freeze'])
    ->whereNumber('invite')->name('invites.freeze');
Route::patch('/invites/{invite}/unfreeze', [AdminReferralController::class, 'unfreeze'])
    ->whereNumber('invite')->name('invites.unfreeze');

Route::get('/grants', [AdminReferralController::class, 'grants'])->name('grants.index');
Route::get('/servers/{server}/items/{item}', [AdminReferralController::class, 'itemPreview'])
    ->whereNumber(['server', 'item'])->middleware('throttle:120,1')->name('items.preview');
