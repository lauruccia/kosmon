<?php

use App\Models\PaymentRequest;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Authorizations
|--------------------------------------------------------------------------
| Il canale payment-request.{token} è pubblico (il token è già un segreto
| one-time non indovinabile). Solo chi conosce il token può ascoltare.
*/

// Canale pubblico: chiunque conosca il token può ascoltare lo stato.
// Il token è UUID + random, di fatto impossibile da bruteforce.
Broadcast::channel('payment-request.{token}', function ($user, string $token) {
    return PaymentRequest::where('token', $token)->exists();
});
