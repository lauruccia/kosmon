<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Verifica che una sessione Stripe Checkout sia davvero il pagamento di QUESTO
 * acquisto, e per l'importo giusto.
 *
 * Perche' esiste: fino al 28/08/2026 le pagine di successo (KyCardController::
 * success e PlanSubscriptionController::success) prendevano il session_id
 * dall'indirizzo (?session_id=...), chiedevano a Stripe se risultava pagato e
 * accreditavano. Il session_id non veniva mai confrontato con quello salvato
 * sull'acquisto: chi aveva pagato una volta poteva creare acquisti nuovi e
 * incollare all'infinito quel link, facendosi accreditare KY senza pagare.
 *
 * Le due regole di questa classe:
 *  1. il session_id NON si prende mai dalla richiesta, ma solo dalla colonna
 *     stripe_checkout_session_id scritta quando la sessione e' stata creata;
 *  2. la sessione deve essere pagata, riferita a questo acquisto
 *     (client_reference_id) e dell'importo esatto, nella valuta giusta.
 */
class StripeCheckoutVerifier
{
    /**
     * Interroga Stripe sulla sessione SALVATA sull'acquisto e dice se e' un
     * pagamento valido per questo acquisto.
     *
     * @param string|null $storedSessionId    valore della colonna stripe_checkout_session_id
     * @param int         $expectedAmountCents importo atteso, in centesimi di euro
     * @param string      $expectedReference   uuid dell'acquisto (client_reference_id della sessione)
     * @param string      $context             etichetta per i log
     */
    public function isPaidFor(?string $storedSessionId, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
    {
        if ($storedSessionId === null || $storedSessionId === '') {
            // Nessuna sessione salvata: il checkout non e' mai partito.
            return false;
        }

        if (!config('services.stripe.secret')) {
            Log::error('Stripe verify: chiave segreta non configurata', ['context' => $context]);
            return false;
        }

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $session = \Stripe\Checkout\Session::retrieve($storedSessionId);
        } catch (\Exception $e) {
            Log::warning('Stripe verify: sessione non recuperabile', [
                'context'    => $context,
                'session_id' => $storedSessionId,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }

        return $this->sessionMatches($session, $expectedAmountCents, $expectedReference, $context);
    }

    /**
     * Controlla una sessione gia' in mano (es. quella dentro un evento webhook
     * con firma verificata) contro l'acquisto atteso.
     */
    public function sessionMatches(object $session, int $expectedAmountCents, string $expectedReference, string $context = 'stripe'): bool
    {
        $sessionId = $session->id ?? null;

        if (($session->payment_status ?? null) !== 'paid') {
            Log::warning('Stripe verify: sessione non pagata', [
                'context'        => $context,
                'session_id'     => $sessionId,
                'payment_status' => $session->payment_status ?? null,
            ]);
            return false;
        }

        // La sessione deve riferirsi a QUESTO acquisto. E' il controllo che
        // chiude il riuso di un link di pagamento gia' usato.
        $reference = $session->client_reference_id ?? null;
        if ($reference !== null && $reference !== $expectedReference) {
            Log::error('Stripe verify: sessione riferita a un altro acquisto', [
                'context'    => $context,
                'session_id' => $sessionId,
                'atteso'     => $expectedReference,
                'trovato'    => $reference,
            ]);
            return false;
        }

        $currency = strtolower((string) ($session->currency ?? ''));
        if ($currency !== '' && $currency !== 'eur') {
            Log::error('Stripe verify: valuta diversa da EUR', [
                'context'    => $context,
                'session_id' => $sessionId,
                'currency'   => $currency,
            ]);
            return false;
        }

        // Importo: deve coincidere al centesimo con il prezzo registrato
        // sull'acquisto. Se qui compare un errore in log, il prezzo su Stripe
        // e quello a database si sono disallineati: NON e' un attacco, ma
        // l'accredito resta fermo finche' i due non tornano uguali.
        $paidCents = $session->amount_total ?? null;
        if ($paidCents === null || (int) $paidCents !== $expectedAmountCents) {
            Log::error('Stripe verify: importo pagato diverso da quello atteso', [
                'context'    => $context,
                'session_id' => $sessionId,
                'atteso'     => $expectedAmountCents,
                'pagato'     => $paidCents,
            ]);
            return false;
        }

        return true;
    }
}
