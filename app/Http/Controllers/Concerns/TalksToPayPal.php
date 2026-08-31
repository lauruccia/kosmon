<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Le due chiamate di servizio a PayPal (base URL e token), identiche in
 * KyCardController e PlanSubscriptionController dove vivono ancora come
 * metodi privati copiati.
 *
 * Qui esistono per il terzo caso — la quota di iscrizione — senza toccare i
 * due controller che funzionano: unificarli sarebbe giusto, ma e' una
 * modifica a due percorsi di incasso gia' in produzione che va fatta con i
 * suoi test, non di straforo dentro una funzione nuova.
 */
trait TalksToPayPal
{
    protected function paypalApiBase(): string
    {
        return config('services.paypal.mode', 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    protected function getPaypalAccessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
            ->post($this->paypalApiBase() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        return (string) $response->json('access_token');
    }
}
