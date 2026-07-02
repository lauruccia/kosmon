<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\PaymentPlanController;
use App\Http\Controllers\Api\V1\PaymentRequestController;
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

Route::prefix('v1')->middleware([ApiTokenAuth::class, 'throttle:60,1'])->group(function () {

    // Account / saldo
    Route::get('/me', [AccountController::class, 'me'])->name('api.v1.me');
    Route::get('/balance', [AccountController::class, 'balance'])->name('api.v1.balance');

    // Trasferimenti
    Route::get('/transfers', [TransferController::class, 'index'])->name('api.v1.transfers.index');
    Route::get('/transfers/{uuid}', [TransferController::class, 'show'])->name('api.v1.transfers.show');
    Route::post('/transfers', [TransferController::class, 'store'])
        ->name('api.v1.transfers.store')
        ->middleware([ApiTokenAuth::class . ':write', 'throttle:10,1']);

    // Piani rateali
    Route::get('/payment-plans', [PaymentPlanController::class, 'index'])->name('api.v1.payment-plans.index');
    Route::get('/payment-plans/{uuid}', [PaymentPlanController::class, 'show'])->name('api.v1.payment-plans.show');

    // Richieste di pagamento
    Route::get('/payment-requests', [PaymentRequestController::class, 'index'])->name('api.v1.payment-requests.index');
    Route::get('/payment-requests/{uuid}', [PaymentRequestController::class, 'show'])->name('api.v1.payment-requests.show');
    Route::post('/payment-requests', [PaymentRequestController::class, 'store'])
        ->name('api.v1.payment-requests.store')
        ->middleware([ApiTokenAuth::class . ':write', 'throttle:10,1']);
});
