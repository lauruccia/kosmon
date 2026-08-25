<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\EcommercePairingController;
use App\Http\Controllers\Api\V1\PaymentPlanController;
use App\Http\Controllers\Api\V1\PaymentRequestController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\MandateController;
use App\Http\Controllers\Api\V1\UserInfoController;
use App\Http\Controllers\OAuth\TokenController as OAuthTokenController;
use App\Http\Middleware\ApiTokenAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| KMoney API v1 — Autenticazione via Bearer token
|--------------------------------------------------------------------------
| Header richiesto: Authorization: Bearer km_xxxxxxxxxxxx
| Tutti gli endpoint restituiscono JSON.
*/

// Collegamento plugin e-commerce con solo numero di conto: endpoint PUBBLICI
// (il plugin non ha ancora un token). Rate limit stretto; le credenziali sono
// emesse solo dopo approvazione dell'admin e ritirate col claim_secret.
Route::prefix('v1/ecommerce')->middleware('throttle:10,1')->group(function () {
    Route::post('/pairings', [EcommercePairingController::class, 'store'])->name('api.v1.ecommerce.pairings.store');
    Route::get('/pairings/{uuid}', [EcommercePairingController::class, 'show'])->name('api.v1.ecommerce.pairings.show');
});

/*
|--------------------------------------------------------------------------
| "Accedi con KMoney" — OAuth2 (authorization_code + PKCE)
|--------------------------------------------------------------------------
| Il consenso dell'utente sta su /oauth/authorize (routes/web.php, con la
| sessione e la catena di middleware del portale). Qui ci sono i due endpoint
| "sul retro", che parla solo il server dell'applicazione collegata: nessuna
| sessione, nessun CSRF, autenticazione col segreto del client.
*/
Route::prefix('oauth')->middleware('throttle:30,1')->group(function () {
    Route::post('/token', [OAuthTokenController::class, 'issue'])->name('api.oauth.token');
    Route::post('/token/revoke', [OAuthTokenController::class, 'revoke'])->name('api.oauth.token.revoke');
});

// Identità dell'utente collegato: è ciò che evita a kshop una seconda anagrafica.
Route::prefix('v1')->middleware(['oauth.token', 'throttle:60,1'])->group(function () {
    Route::get('/userinfo', [UserInfoController::class, 'show'])->name('api.v1.userinfo');
});

// Mandato di pagamento (fase 2a): l'addebito in un clic. Scope `mandate`
// obbligatorio — un token con i soli permessi di lettura non muove KY.
// Il throttle è stretto: sono soldi, e l'antifurto del mandato lavora su una
// finestra di un'ora, non sul singolo minuto.
Route::prefix('v1')->middleware(['oauth.token:mandate', 'throttle:30,1'])->group(function () {
    Route::get('/mandates', [MandateController::class, 'index'])->name('api.v1.mandates.index');
    Route::post('/mandates/{uuid}/charge', [MandateController::class, 'charge'])->name('api.v1.mandates.charge');
});

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
