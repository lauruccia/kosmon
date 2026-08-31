<?php

namespace App\Support;

use App\Models\Account;

/**
 * Chiave di idempotenza per i pagamenti che partono da un FORM.
 *
 * `TransferBookingService::book()` riconosce gia' un invio ripetuto — se
 * esiste un transfer con la stessa `idempotency_key` lo restituisce invece di
 * riaddebitare — e `transfers.idempotency_key` e' UNIQUE dalla migrazione
 * iniziale. Ma passandogli `Str::uuid()`, cioe' una chiave nuova a ogni POST,
 * quella capacita' veniva buttata via: ogni reinvio arrivava travestito da
 * pagamento mai visto (M1, 31/08).
 *
 * La chiave e' composta da tre cose, e ognuna serve:
 *
 * - il **token del form**, generato a ogni caricamento della pagina e
 *   nascosto nel form: e' lo stesso per tutti i reinvii dello stesso form —
 *   doppio submit, tasto indietro, retry di rete — e cambia a ogni
 *   ricaricamento, cosi' chi vuole davvero rifare lo stesso pagamento puo';
 * - il **conto pagatore**, senza il quale chi indovinasse il token di un altro
 *   verrebbe rimandato alla ricevuta di un pagamento non suo. Con l'id del
 *   conto nella chiave ognuno puo' collidere solo con se stesso;
 * - il **payload** (destinatario, importo, causale): tornare indietro e
 *   cambiare importo e' un pagamento diverso, e deve passare.
 *
 * Senza token — client vecchio, POST diretto all'endpoint, JavaScript rotto —
 * si ricade su una finestra di un minuto: meno preciso, ma pur sempre una
 * chiave STABILE, mentre quella casuale non proteggeva da niente.
 *
 * Il prefisso separa i flussi: un pagamento inviato dal portale e uno operato
 * da un broker non devono potersi scambiare la chiave.
 */
final class PaymentIdempotency
{
    public static function forForm(string $prefix, Account $fromAccount, ?string $token, array $payload): string
    {
        $token = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $token);

        if ($token === '') {
            $token = 'senza-form-' . now()->format('Y-m-d-H-i');
        }

        $impronta = hash('sha256', implode('|', array_merge([$token], array_map(
            static fn ($valore) => (string) $valore,
            $payload
        ))));

        return $prefix . '_' . $fromAccount->id . '_' . substr($impronta, 0, 40);
    }

    /** Token nuovo a ogni caricamento della pagina che contiene il form. */
    public static function freshToken(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }
}
