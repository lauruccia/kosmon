<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Il carrello: metterci dentro, toglierne, e portarlo alla cassa.
 *
 * Fase C del piano carrello (PIANO_CARRELLO_VARIANTI.md).
 *
 * Alla cassa succede la cosa che dà senso a tutta la fase B: il carrello viene
 * **spezzato per venditore** e ogni gruppo diventa un ordine per conto suo, con
 * il suo movimento KY. Tre aziende nel carrello = tre ordini e tre movimenti,
 * dentro un'unica transazione: o passano tutti o non passa nessuno.
 *
 * Il saldo si controlla sul TOTALE prima di pagare chiunque. Non è un dettaglio
 * di comodità: controllare venditore per venditore vorrebbe dire scoprire che i
 * soldi non bastano dopo aver già pagato il primo — e ogni tentativo rifiutato
 * dal motore finanziario è un fallimento registrato che, in altri canali del
 * portale, concorre al blocco automatico del conto.
 */
class CartService
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }

    // ── Riempire e svuotare ──────────────────────────────────────────────────

    /**
     * Aggiunge un prodotto al carrello, o ne aumenta la quantità se c'è già.
     *
     * @throws RuntimeException con un messaggio già pronto per l'utente
     */
    public function aggiungi(
        Account $account,
        Listing $listing,
        int $quantita = 1,
        ?ListingVariant $variante = null,
    ): CartItem {
        $quantita = max(1, $quantita);

        if ($listing->status !== 'active' || $listing->is_expired) {
            throw new RuntimeException('Questo prodotto non è più disponibile.');
        }

        if ($listing->company_id === $account->company_id) {
            throw new RuntimeException('Non puoi acquistare un prodotto pubblicato dalla tua stessa azienda.');
        }

        // Prodotto variabile: la combinazione va scelta, e deve essere una sua.
        if ($listing->isVariabile()) {
            if (! $variante) {
                throw new RuntimeException('Scegli una variante prima di aggiungere il prodotto al carrello.');
            }

            if ((int) $variante->listing_id !== (int) $listing->id) {
                throw new RuntimeException('Questa combinazione non appartiene a questo prodotto.');
            }

            if (! $variante->is_active) {
                throw new RuntimeException('Questa combinazione non è più disponibile.');
            }
        } else {
            // Prodotto semplice: una variante non c'entra niente.
            $variante = null;
        }

        $cart = Cart::attivoPer($account);

        $riga = $cart->items()
            ->where('listing_id', $listing->id)
            ->where('listing_variant_id', $variante?->id)
            ->first();

        $nuovaQuantita = ($riga?->quantity ?? 0) + $quantita;

        if ($variante) {
            if ($variante->hasLimitedStock() && $variante->stock_quantity < $nuovaQuantita) {
                throw new RuntimeException(
                    $variante->stock_quantity <= 0
                        ? 'Combinazione esaurita.'
                        : "Disponibili solo {$variante->stock_quantity} pezzi di questa combinazione."
                );
            }
        } elseif ($listing->hasLimitedStock() && $listing->stock_quantity < $nuovaQuantita) {
            throw new RuntimeException(
                $listing->stock_quantity <= 0
                    ? 'Prodotto esaurito.'
                    : "Disponibili solo {$listing->stock_quantity} pezzi."
            );
        }

        if ($riga) {
            $riga->update(['quantity' => $nuovaQuantita]);

            return $riga;
        }

        return $cart->items()->create([
            'listing_id'         => $listing->id,
            'listing_variant_id' => $variante?->id,
            'quantity'           => $nuovaQuantita,
        ]);
    }

    /** Cambia la quantità di una riga. Quantità 0 = togliere la riga. */
    public function aggiornaQuantita(Account $account, CartItem $riga, int $quantita): void
    {
        $this->assertRigaDelConto($account, $riga);

        if ($quantita <= 0) {
            $riga->delete();

            return;
        }

        $listing  = $riga->listing;
        $variante = $riga->variant;

        if ($variante) {
            if ($variante->hasLimitedStock() && $variante->stock_quantity < $quantita) {
                throw new RuntimeException(
                    $variante->stock_quantity <= 0
                        ? 'Combinazione esaurita.'
                        : "Disponibili solo {$variante->stock_quantity} pezzi di questa combinazione."
                );
            }
        } elseif ($listing && $listing->hasLimitedStock() && $listing->stock_quantity < $quantita) {
            throw new RuntimeException(
                $listing->stock_quantity <= 0
                    ? 'Prodotto esaurito.'
                    : "Disponibili solo {$listing->stock_quantity} pezzi."
            );
        }

        $riga->update(['quantity' => $quantita]);
    }

    public function rimuovi(Account $account, CartItem $riga): void
    {
        $this->assertRigaDelConto($account, $riga);
        $riga->delete();
    }

    public function svuota(Account $account): void
    {
        Cart::attivoPer($account)->items()->delete();
    }

    // ── La cassa ─────────────────────────────────────────────────────────────

    /**
     * Trasforma il carrello in ordini: uno per venditore.
     *
     * @return Collection<int, Order>
     *
     * @throws RuntimeException con un messaggio già pronto per l'utente
     */
    public function checkout(Account $account, User $user, ?string $ipAddress = null): Collection
    {
        $cart = Cart::attivoPer($account);
        $cart->load('items.listing.company', 'items.listing.activeOffer', 'items.variant.values.attribute');

        if ($cart->isVuoto()) {
            throw new RuntimeException('Il carrello è vuoto.');
        }

        // 1. Tutto quello che c'è dentro dev'essere ancora comprabile. Un
        //    prodotto può essere stato sospeso o essersi esaurito mentre il
        //    carrello aspettava.
        foreach ($cart->items as $riga) {
            if (! $riga->isDisponibile()) {
                $nome = $riga->listing?->title ?? 'Un prodotto del carrello';
                throw new RuntimeException($nome . ': ' . $riga->motivoIndisponibilita());
            }
        }

        // 2. Il saldo si controlla sul totale, PRIMA di pagare chiunque.
        $totaleKy = $cart->totaleKy();
        $disponibile = $account->saldoDisponibile();

        if ($disponibile < $totaleKy) {
            $mancano = ky_format($totaleKy - $disponibile);
            throw new RuntimeException("Saldo insufficiente: ti mancano {$mancano} KY per completare l'ordine.");
        }

        // 3. Un gruppo per venditore, un ordine per gruppo, tutto dentro la
        //    stessa transazione: se il terzo venditore fallisce, i primi due
        //    non hanno incassato niente.
        $ordini = DB::transaction(function () use ($cart, $account, $user, $ipAddress) {
            $creati = collect();

            foreach ($cart->perVenditore() as $gruppo) {
                $righe = $gruppo['righe']
                    ->map(fn (CartItem $r) => [
                        'listing'  => $r->listing,
                        'variant'  => $r->variant,
                        'quantity' => $r->quantity,
                    ])
                    ->all();

                try {
                    $creati->push(
                        $this->orderService->place(
                            buyerAccount: $account,
                            user: $user,
                            righe: $righe,
                            ipAddress: $ipAddress,
                        )
                    );
                } catch (RuntimeException $e) {
                    // Con più venditori "Prodotto esaurito." da solo non dice
                    // di chi: il nome dell'azienda trasforma un messaggio
                    // misterioso in uno su cui si può agire.
                    throw new RuntimeException($gruppo['company']->name . ': ' . $e->getMessage(), 0, $e);
                }
            }

            $cart->update(['status' => Cart::STATUS_ORDERED]);

            return $creati;
        });

        return $ordini;
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function assertRigaDelConto(Account $account, CartItem $riga): void
    {
        $suo = $riga->cart && (int) $riga->cart->account_id === (int) $account->id;

        if (! $suo) {
            throw new RuntimeException('Questa riga non appartiene al tuo carrello.');
        }
    }
}
