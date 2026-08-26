<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ShippingAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La rubrica degli indirizzi di spedizione (fase A-bis, 26/08/2026).
 *
 * **Questo e' l'unico posto da cui si scrive su `shipping_addresses`.** Il
 * motivo e' che ci sono due invarianti che nessun controller deve poter
 * rompere:
 *
 *   1. **Un conto ha al massimo un predefinito.** Non zero (se ha almeno un
 *      indirizzo) e non due.
 *   2. **`accounts.shipping_*` e' sempre la copia del predefinito.** Quelle
 *      colonne le leggono `Account::hasShippingAddress()`, i form del profilo
 *      e — quando in cassa non si sceglie niente — `OrderService`. Se
 *      divergessero dalla rubrica, un ordine potrebbe partire verso un
 *      indirizzo che l'utente crede di aver cambiato.
 *
 * Il tetto e' `ShippingAddress::MAX_PER_ACCOUNT` (10).
 */
class ShippingAddressBook
{
    /** @return Collection<int, ShippingAddress> */
    public function elenco(Account $account): Collection
    {
        return ShippingAddress::query()
            ->where('account_id', $account->id)
            ->inOrdineDiRubrica()
            ->get();
    }

    /**
     * @param  array<string, string|null>  $dati
     *
     * @throws RuntimeException se la rubrica e' piena
     */
    public function aggiungi(Account $account, array $dati, bool $predefinito = false): ShippingAddress
    {
        return DB::transaction(function () use ($account, $dati, $predefinito) {
            $quanti = ShippingAddress::query()
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->count();

            if ($quanti >= ShippingAddress::MAX_PER_ACCOUNT) {
                throw new RuntimeException(
                    'Hai già ' . ShippingAddress::MAX_PER_ACCOUNT . ' indirizzi salvati: '
                    . 'eliminane uno prima di aggiungerne un altro.'
                );
            }

            // Il primo indirizzo della rubrica e' per forza il predefinito:
            // un conto con un indirizzo e nessun predefinito non spedirebbe
            // piu' niente.
            $diventaPredefinito = $predefinito || $quanti === 0;

            $indirizzo = ShippingAddress::create(array_merge(
                $this->soloCampiIndirizzo($dati),
                ['account_id' => $account->id, 'is_default' => $diventaPredefinito],
            ));

            if ($diventaPredefinito) {
                $this->impostaPredefinito($account, $indirizzo);
            }

            return $indirizzo;
        });
    }

    /** @param array<string, string|null> $dati */
    public function modifica(Account $account, ShippingAddress $indirizzo, array $dati): ShippingAddress
    {
        $this->assertDelConto($account, $indirizzo);

        return DB::transaction(function () use ($account, $indirizzo, $dati) {
            $indirizzo->fill($this->soloCampiIndirizzo($dati))->save();

            // Se ho appena corretto il predefinito, la copia sul conto e'
            // vecchia di un istante: va riallineata subito.
            if ($indirizzo->is_default) {
                $this->copiaSulConto($account, $indirizzo);
            }

            return $indirizzo->fresh();
        });
    }

    public function rendiPredefinito(Account $account, ShippingAddress $indirizzo): void
    {
        $this->assertDelConto($account, $indirizzo);

        DB::transaction(fn () => $this->impostaPredefinito($account, $indirizzo));
    }

    /**
     * Eliminare un indirizzo non tocca nessun ordine gia' fatto:
     * `orders.shipping_*` e' uno snapshot preso al momento dell'acquisto.
     */
    public function elimina(Account $account, ShippingAddress $indirizzo): void
    {
        $this->assertDelConto($account, $indirizzo);

        DB::transaction(function () use ($account, $indirizzo) {
            $eraPredefinito = $indirizzo->is_default;
            $indirizzo->delete();

            if (! $eraPredefinito) {
                return;
            }

            // Il predefinito se ne va: promuovo il piu' recente fra quelli
            // rimasti. Se non ne restano, il conto torna senza indirizzo — ed
            // e' giusto cosi': non si puo' spedire da nessuna parte.
            $prossimo = ShippingAddress::query()
                ->where('account_id', $account->id)
                ->orderByDesc('id')
                ->first();

            if ($prossimo) {
                $this->impostaPredefinito($account, $prossimo);
            } else {
                $this->svuotaConto($account);
            }
        });
    }

    /**
     * Il predefinito del conto, o null se la rubrica e' vuota.
     */
    public function predefinito(Account $account): ?ShippingAddress
    {
        return ShippingAddress::query()
            ->where('account_id', $account->id)
            ->inOrdineDiRubrica()
            ->first();
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function impostaPredefinito(Account $account, ShippingAddress $indirizzo): void
    {
        ShippingAddress::query()
            ->where('account_id', $account->id)
            ->where('id', '!=', $indirizzo->id)
            ->update(['is_default' => false]);

        $indirizzo->forceFill(['is_default' => true])->save();

        $this->copiaSulConto($account, $indirizzo);
    }

    private function copiaSulConto(Account $account, ShippingAddress $indirizzo): void
    {
        $account->forceFill($indirizzo->comeCampiShipping())->save();
    }

    private function svuotaConto(Account $account): void
    {
        $account->forceFill([
            'shipping_recipient_name' => null,
            'shipping_address'        => null,
            'shipping_city'           => null,
            'shipping_postal_code'    => null,
            'shipping_province'       => null,
            'shipping_phone'          => null,
        ])->save();
    }

    /**
     * @param  array<string, string|null>  $dati
     * @return array<string, string|null>
     */
    private function soloCampiIndirizzo(array $dati): array
    {
        $campi = ['label', 'recipient_name', 'address', 'city', 'postal_code', 'province', 'phone'];

        $puliti = [];
        foreach ($campi as $campo) {
            if (! array_key_exists($campo, $dati)) {
                continue;
            }
            $valore = is_string($dati[$campo]) ? trim($dati[$campo]) : $dati[$campo];
            $puliti[$campo] = ($valore === '' ? null : $valore);
        }

        return $puliti;
    }

    private function assertDelConto(Account $account, ShippingAddress $indirizzo): void
    {
        if ((int) $indirizzo->account_id !== (int) $account->id) {
            throw new RuntimeException('Questo indirizzo non appartiene alla tua rubrica.');
        }
    }
}
