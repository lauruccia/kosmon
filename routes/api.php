<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KMoney API v1 — Autenticazione via Bearer token
|--------------------------------------------------------------------------
| Header richiesto: Authorization: Bearer km_xxxxxxxxxxxx
| Tutti gli endpoint restituiscono JSON.
*/

Route::prefix('v1')->middleware(ApiTokenAuth::class)->group(function () {

    // Account / saldo
    Route::get('/me', [AccountController::class, 'me'])->name('api.v1.me');

    // Trasferimenti
    Route::get('/transfers', [TransferController::class, 'index'])->name('api.v1.transfers.index');
    Route::get('/transfers/{uuid}', [TransferController::class, 'show'])->name('api.v1.transfers.show');
    Route::post('/transfers', [TransferController::class, 'store'])
        ->name('api.v1.transfers.store')
        ->middleware([ApiTokenAuth::class . ':write', 'throttle:10,1']);
});
