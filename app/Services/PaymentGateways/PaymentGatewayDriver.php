<?php

namespace App\Services\PaymentGateways;

use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Contratto comune ai driver di pagamento EUR (Stripe, PayPal, Bonifico).
 *
 * Ogni driver usa le credenziali INDIPENDENTI dell'azienda venditrice
 * (PaymentGateway::credentials) — Kosmopay non intermedia mai i soldi EUR,
 * li fa solo arrivare sul conto proprio dell'azienda.
 */
interface PaymentGatewayDriver
{
    /**
     * Avvia il pagamento. Ritorna l'URL a cui reindirizzare l'acquirente
     * (Stripe Checkout / PayPal approve link), oppure null se il provider
     * non prevede un redirect (es. bonifico: si passa direttamente a
     * "awaiting_confirmation" mostrando le istruzioni IBAN).
     *
     * Deve salvare su $payment (provider_reference, status) quanto serve
     * per la successiva verify().
     */
    public function initiate(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): ?string;

    /**
     * Verifica/conferma il pagamento al ritorno dell'acquirente (Stripe/
     * PayPal) o su azione esplicita del venditore (bonifico). Aggiorna
     * $payment->status e, se pagato, paid_at. Ritorna true se risulta pagato.
     */
    public function verify(
        PaymentGateway $gateway,
        MarketplaceOrderPayment $payment,
        Request $request
    ): bool;
}
