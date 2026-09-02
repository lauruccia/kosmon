<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifica che un ordine PayPal sia davvero il pagamento di QUESTO acquisto, e
 * per l'importo giusto. Gemello di StripeCheckoutVerifier, e nasce dallo
 * stesso motivo per cui esiste quello (02/09/2026).
 *
 * IL BUCO CHE CHIUDE. PayPal, in questo progetto, non ha nessun webhook: le
 * quattro strade che accreditano un incasso Stripe — webhook, pagina di esito,
 * conferma dell'admin, ripescaggio — per PayPal si riducevano a UNA, la
 * `capture` sincrona al ritorno dal sito di PayPal. Se l'utente chiudeva la
 * scheda, la rete cadeva, o la scrittura falliva, i soldi erano incassati e
 * nessun processo automatico li recuperava piu'. Sui privati restava almeno il
 * bottone di ripescaggio in backoffice; sugli agenti non restava niente.
 *
 * Le tre regole, le stesse di Stripe:
 *  1. l'order_id NON si prende mai dalla richiesta, ma solo dalla colonna
 *     paypal_order_id scritta quando l'ordine e' stato creato;
 *  2. l'ordine dev'essere COMPLETED, cioe' catturato davvero — `APPROVED` vuol
 *     dire che l'utente ha detto di si' e basta, nessun soldo si e' mosso;
 *  3. dev'essere riferito a questo acquisto (custom_id) e dell'importo esatto,
 *     nella valuta giusta.
 *
 * SULL'IMPORTO SI E' SEVERI, e cambia rispetto al controllo che c'era prima
 * nel solo ripescaggio dei privati, che si accontentava dello stato: se qui
 * compare un errore in log, il prezzo su PayPal e quello a database si sono
 * disallineati. Non e' per forza un attacco, ma l'accredito resta fermo finche'
 * i due non tornano uguali — esattamente come per Stripe.
 */
class PayPalOrderVerifier
{
    /**
     * Interroga PayPal sull'ordine SALVATO sull'acquisto e dice se e' un
     * pagamento valido per questo acquisto.
     *
     * @param string|null $storedOrderId       valore della colonna paypal_order_id
     * @param int         $expectedAmountCents importo atteso, in centesimi di euro
     * @param string      $expectedReference   uuid dell'acquisto (custom_id dell'ordine)
     * @param string      $context             etichetta per i log
     */
    public function isCompletedFor(?string $storedOrderId, int $expectedAmountCents, string $expectedReference, string $context = 'paypal'): bool
    {
        if ($storedOrderId === null || $storedOrderId === '') {
            // Nessun ordine salvato: il pagamento non e' mai partito.
            return false;
        }

        if (! config('services.paypal.client_id') || ! config('services.paypal.secret')) {
            Log::error('PayPal verify: credenziali non configurate', ['context' => $context]);

            return false;
        }

        try {
            $base = config('services.paypal.mode', 'sandbox') === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com';

            $token = (string) Http::asForm()
                ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
                ->post($base . '/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->json('access_token');

            if ($token === '') {
                Log::error('PayPal verify: token non ottenuto', ['context' => $context]);

                return false;
            }

            $ordine = Http::withToken($token)
                ->get($base . '/v2/checkout/orders/' . $storedOrderId)
                ->json();
        } catch (\Throwable $e) {
            // \Throwable e non \Exception: una libreria mancante solleva un
            // \Error, che un catch(\Exception) lascerebbe passare fino alla
            // pagina bianca (e' il difetto visto in produzione l'01/09 con
            // Stripe).
            Log::warning('PayPal verify: ordine non recuperabile', [
                'context'  => $context,
                'order_id' => $storedOrderId,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }

        return $this->orderMatches($ordine, $expectedAmountCents, $expectedReference, $context);
    }

    /**
     * Controlla un ordine gia' in mano contro l'acquisto atteso.
     *
     * @param array<string,mixed>|null $ordine
     */
    public function orderMatches(?array $ordine, int $expectedAmountCents, string $expectedReference, string $context = 'paypal'): bool
    {
        if ($ordine === null) {
            return false;
        }

        $orderId = $ordine['id'] ?? null;

        if (($ordine['status'] ?? null) !== 'COMPLETED') {
            Log::warning('PayPal verify: ordine non incassato', [
                'context'  => $context,
                'order_id' => $orderId,
                'status'   => $ordine['status'] ?? null,
            ]);

            return false;
        }

        $unit = $ordine['purchase_units'][0] ?? null;

        if (! is_array($unit)) {
            Log::error('PayPal verify: ordine senza purchase_units', [
                'context'  => $context,
                'order_id' => $orderId,
            ]);

            return false;
        }

        // L'ordine deve riferirsi a QUESTO acquisto. E' il controllo che chiude
        // il riuso di un ordine gia' pagato per farsi accreditare un secondo
        // acquisto — la stessa cosa che su Stripe fa client_reference_id.
        $reference = $unit['custom_id'] ?? null;
        if ($reference !== null && $reference !== $expectedReference) {
            Log::error('PayPal verify: ordine riferito a un altro acquisto', [
                'context'  => $context,
                'order_id' => $orderId,
                'atteso'   => $expectedReference,
                'trovato'  => $reference,
            ]);

            return false;
        }

        $currency = strtolower((string) ($unit['amount']['currency_code'] ?? ''));
        if ($currency !== '' && $currency !== 'eur') {
            Log::error('PayPal verify: valuta diversa da EUR', [
                'context'  => $context,
                'order_id' => $orderId,
                'currency' => $currency,
            ]);

            return false;
        }

        $valore = $unit['amount']['value'] ?? null;
        if ($valore === null) {
            Log::error('PayPal verify: ordine senza importo', [
                'context'  => $context,
                'order_id' => $orderId,
            ]);

            return false;
        }

        // PayPal manda l'importo come stringa decimale ("480.00"): si arrotonda
        // per non lasciare che un errore di virgola mobile decida un accredito.
        $pagatiCents = (int) round(((float) $valore) * 100);

        if ($pagatiCents !== $expectedAmountCents) {
            Log::error('PayPal verify: importo pagato diverso da quello atteso', [
                'context'  => $context,
                'order_id' => $orderId,
                'atteso'   => $expectedAmountCents,
                'pagato'   => $pagatiCents,
            ]);

            return false;
        }

        return true;
    }
}
