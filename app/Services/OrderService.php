<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentGateway;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registra un ordine dello shop: controlla, addebita, scala le scorte e scrive
 * l'ordine con le sue righe. Tutto dentro una transazione sola.
 *
 * Nasce nella fase B del piano carrello (PIANO_CARRELLO_VARIANTI.md) e non è
 * codice nuovo: è il corpo di `ListingController::buy()` spostato di posto,
 * generalizzato da "un prodotto" a "una lista di righe". Il controller adesso
 * lo chiama con una riga sola, e i test di regressione della fase A — scritti
 * PRIMA di questo spostamento — devono continuare a passare senza essere
 * ritoccati. È quello il metro: se un test va aggiornato per farlo passare,
 * qualcosa è cambiato che non doveva cambiare.
 *
 * REGOLA CHE NON SI TOCCA: un ordine ha un solo venditore. Il carrello (fase C)
 * raggrupperà le righe per azienda e chiamerà questo servizio una volta per
 * gruppo, dentro una transazione che le abbraccia tutte — così o passano tutti
 * gli ordini o non ne passa nessuno.
 *
 * Il motore finanziario non viene toccato: si continua a chiamare
 * `TransferBookingService::book()` con gli stessi identici parametri di prima.
 *
 * Importi sempre in CENTESIMI.
 */
class OrderService
{
    public function __construct(
        private readonly TransferBookingService $bookingService,
    ) {
    }

    /**
     * @param  array<int, array{listing: Listing, quantity: int, variant?: ListingVariant|null}>  $righe
     *         Tutte righe dello STESSO venditore. `variant` e' obbligatoria per
     *         i prodotti variabili e assente per gli altri.
     *
     * @throws RuntimeException con un messaggio già pronto per l'utente
     */
    public function place(
        Account $buyerAccount,
        User $user,
        array $righe,
        ?string $ipAddress = null,
    ): Order {
        if ($righe === []) {
            throw new RuntimeException('Non c\'è niente da acquistare.');
        }

        $companyIds = collect($righe)->pluck('listing.company_id')->unique();
        if ($companyIds->count() > 1) {
            // Difesa in profondità: chi chiama deve aver già raggruppato. Un
            // ordine con due venditori vorrebbe dire due movimenti sotto un
            // totale solo, cioè un ordine che non si può né rimborsare né
            // spedire come unità.
            throw new RuntimeException('Un ordine può contenere prodotti di un solo venditore.');
        }

        return DB::transaction(function () use ($buyerAccount, $user, $righe, $ipAddress) {
            // I lock si prendono SEMPRE in ordine di id crescente. Con una riga
            // sola non cambia niente; col carrello è ciò che evita il blocco
            // incrociato fra due clienti che comprano gli stessi due prodotti
            // in ordine opposto.
            $idsOrdinati = collect($righe)
                ->map(fn (array $riga) => (int) $riga['listing']->id)
                ->sort()
                ->values()
                ->all();

            $bloccati = Listing::query()
                ->whereIn('id', $idsOrdinati)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // La chiave e' "prodotto + combinazione": la M e la L dello stesso
            // maglione sono due righe distinte dell'ordine, con scorte distinte.
            $perChiave = [];
            foreach ($righe as $riga) {
                $listingId = (int) $riga['listing']->id;
                $variante  = $riga['variant'] ?? null;
                $chiave    = $listingId . ':' . ($variante?->id ?? 0);

                if (! isset($perChiave[$chiave])) {
                    $perChiave[$chiave] = [
                        'listing_id' => $listingId,
                        'variant'    => $variante,
                        'quantita'   => 0,
                    ];
                }

                $perChiave[$chiave]['quantita'] += max(1, (int) $riga['quantity']);
            }

            $company        = null;
            $itemsDaCreare  = [];
            $totaleKy       = 0;
            $totaleEuro     = 0;
            $serveSpedizione = false;

            foreach ($perChiave as $riga) {
                $listing  = $bloccati->get($riga['listing_id']);
                $quantita = $riga['quantita'];

                if (! $listing || $listing->status !== 'active') {
                    throw new RuntimeException('Questo prodotto non è più disponibile.');
                }

                // La combinazione viene ricaricata QUI, bloccata: il prezzo e le
                // scorte che contano sono quelli di adesso, non quelli che aveva
                // in mano chi ha riempito il carrello.
                $variante = null;
                if ($listing->has_variants || ($riga['variant'] !== null)) {
                    $variante = $riga['variant']
                        ? ListingVariant::query()->lockForUpdate()->find($riga['variant']->id)
                        : null;

                    if (! $variante || (int) $variante->listing_id !== (int) $listing->id) {
                        throw new RuntimeException('Scegli una variante di questo prodotto prima di acquistarlo.');
                    }

                    if (! $variante->is_active) {
                        throw new RuntimeException('Questa combinazione non è più disponibile.');
                    }

                    $variante->setRelation('listing', $listing);

                    if ($variante->hasLimitedStock() && $variante->stock_quantity < $quantita) {
                        throw new RuntimeException(
                            $variante->stock_quantity <= 0
                                ? 'Combinazione esaurita.'
                                : "Disponibili solo {$variante->stock_quantity} pezzi di questa combinazione."
                        );
                    }
                } elseif ($listing->hasLimitedStock() && $listing->stock_quantity < $quantita) {
                    throw new RuntimeException(
                        $listing->stock_quantity <= 0
                            ? 'Prodotto esaurito.'
                            : "Disponibili solo {$listing->stock_quantity} pezzi."
                    );
                }

                $company ??= $listing->company;

                // effective_* e non i campi grezzi: se c'è un'offerta attiva si
                // paga il prezzo dell'offerta, col mix dell'offerta. Con una
                // combinazione, al prezzo base si somma il suo delta.
                $unitPieno = $variante ? $variante->prezzoEffettivo() : (int) $listing->effective_price_ky;
                $unitKy    = $variante ? $variante->quotaKy() : $listing->effective_ky_amount;
                $unitEuro  = $variante ? $variante->quotaEuro() : $listing->effective_euro_amount;

                $totaleKy   += $unitKy * $quantita;
                $totaleEuro += $unitEuro * $quantita;

                if ($listing->requiresShippingAddress()) {
                    $serveSpedizione = true;
                }

                $itemsDaCreare[] = [
                    'listing'         => $listing,
                    'variant'         => $variante,
                    'quantity'        => $quantita,
                    'unit_price_ky'   => $unitPieno,
                    'ky_percentage'   => $listing->effective_ky_percentage,
                    'unit_ky_amount'  => $unitKy,
                    'unit_eur_amount' => $unitEuro,
                    'line_ky_amount'  => $unitKy * $quantita,
                    'line_eur_amount' => $unitEuro * $quantita,
                ];
            }

            // La spedizione si paga UNA volta per ordine, non per riga e non
            // per quantità: un ordine è un pacco. Divisa KY/EUR con la stessa
            // percentuale del prodotto che la richiede (scelta del 29/07/2025).
            $spedizioneKy   = 0;
            $spedizioneEuro = 0;
            if ($serveSpedizione) {
                $conSpedizione = collect($itemsDaCreare)
                    ->map(fn (array $i) => $i['listing'])
                    ->filter(fn (Listing $l) => $l->requiresShippingAddress())
                    ->sortByDesc(fn (Listing $l) => $l->shipping_cost)
                    ->first();

                $spedizioneKy   = (int) $conSpedizione->shipping_ky_amount;
                $spedizioneEuro = (int) $conSpedizione->shipping_euro_amount;

                $totaleKy   += $spedizioneKy;
                $totaleEuro += $spedizioneEuro;
            }

            $this->assertVenditorePuoIncassareEuro($company, $totaleEuro);
            $this->assertIndirizzoCompleto($buyerAccount, $serveSpedizione);

            $sellerAccount = $this->contoDelVenditore($company);

            $order = Order::create([
                'buyer_account_id'  => $buyerAccount->id,
                'buyer_user_id'     => $user->id,
                'company_id'        => $company->id,
                'seller_account_id' => $sellerAccount->id,
                'status'            => $totaleEuro > 0 ? Order::STATUS_PENDING_PAYMENT : Order::STATUS_PAID,
                'total_ky'          => $totaleKy,
                'total_eur'         => $totaleEuro,
                'shipping_ky'       => $spedizioneKy,
                'shipping_eur'      => $spedizioneEuro,
                'shipping_recipient_name' => $serveSpedizione ? $buyerAccount->shipping_recipient_name : null,
                'shipping_address'        => $serveSpedizione ? $buyerAccount->shipping_address : null,
                'shipping_city'           => $serveSpedizione ? $buyerAccount->shipping_city : null,
                'shipping_postal_code'    => $serveSpedizione ? $buyerAccount->shipping_postal_code : null,
                'shipping_province'       => $serveSpedizione ? $buyerAccount->shipping_province : null,
                'shipping_phone'          => $serveSpedizione ? $buyerAccount->shipping_phone : null,
                'source'                  => Transfer::ORDER_SOURCE_INTERNAL,
            ]);

            foreach ($itemsDaCreare as $item) {
                OrderItem::create([
                    'order_id'           => $order->id,
                    'listing_id'         => $item['listing']->id,
                    'listing_variant_id' => $item['variant']?->id,
                    'title'              => $item['listing']->title,
                    // Snapshot come il titolo: se domani il venditore cancella
                    // la combinazione o l'admin rinomina "rosso", l'ordine di
                    // ieri resta leggibile.
                    'variant_label'      => $item['variant']?->etichetta,
                    'quantity'        => $item['quantity'],
                    'unit_price_ky'   => $item['unit_price_ky'],
                    'ky_percentage'   => $item['ky_percentage'],
                    'unit_ky_amount'  => $item['unit_ky_amount'],
                    'unit_eur_amount' => $item['unit_eur_amount'],
                    'line_ky_amount'  => $item['line_ky_amount'],
                    'line_eur_amount' => $item['line_eur_amount'],
                ]);
            }

            $transfer = $this->bookingService->book([
                'initiated_by'    => $user->id,
                'from_account_id' => $buyerAccount->id,
                'to_account_id'   => $sellerAccount->id,
                'amount'          => $totaleKy,
                'kind'            => 'portal_marketplace_order',
                'description'     => $this->descrizione($itemsDaCreare, $spedizioneKy, $spedizioneEuro),
                'order_id'        => $order->id,
                // Campi storici del movimento: restano com'erano, così i
                // movimenti già in produzione e quelli nuovi si leggono allo
                // stesso modo. Con più righe, il movimento porta il titolo
                // della prima e la quantità totale.
                'listing_id'      => $itemsDaCreare[0]['listing']->id,
                'quantity'        => (int) collect($itemsDaCreare)->sum('quantity'),
                'order_title'     => $order->summary_title,
                'order_source'    => Transfer::ORDER_SOURCE_INTERNAL,
                'shipping_recipient_name' => $order->shipping_recipient_name,
                'shipping_address'        => $order->shipping_address,
                'shipping_city'           => $order->shipping_city,
                'shipping_postal_code'    => $order->shipping_postal_code,
                'shipping_province'       => $order->shipping_province,
                'shipping_phone'          => $order->shipping_phone,
                'shipping_ky_amount'      => $serveSpedizione ? $spedizioneKy : null,
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $ipAddress,
            ]);

            foreach ($itemsDaCreare as $item) {
                // Con una combinazione le scorte si scalano SU DI LEI: il
                // prodotto può restare pieno di magliette e non avere più la M.
                if ($item['variant']) {
                    if ($item['variant']->hasLimitedStock()) {
                        $item['variant']->decrement('stock_quantity', $item['quantity']);
                    }
                } elseif ($item['listing']->hasLimitedStock()) {
                    $item['listing']->decrement('stock_quantity', $item['quantity']);
                }
            }

            $payment = null;
            if ($totaleEuro > 0) {
                $payment = MarketplaceOrderPayment::create([
                    'transfer_id' => $transfer->id,
                    'order_id'    => $order->id,
                    'listing_id'  => $itemsDaCreare[0]['listing']->id,
                    'company_id'  => $company->id,
                    'amount'      => $totaleEuro,
                    'status'      => MarketplaceOrderPayment::STATUS_PENDING,
                ]);
            }

            // Le relazioni sono già in mano: gliele attacco invece di far
            // rileggere il database a chi ha chiamato.
            $order->setRelation('transfer', $transfer);
            $order->setRelation('payment', $payment);
            $order->setRelation('company', $company);

            return $order;
        });
    }

    /**
     * Se c'è una quota in euro, il venditore deve avere un metodo di pagamento
     * attivo E configurato. Si controlla PRIMA di addebitare i KY, per non
     * lasciare l'acquirente con dei KY spesi e nessun modo di saldare gli euro.
     */
    private function assertVenditorePuoIncassareEuro($company, int $totaleEuro): void
    {
        if ($totaleEuro <= 0) {
            return;
        }

        $haGateway = PaymentGateway::query()
            ->where('company_id', $company->id)
            ->active()
            ->get()
            ->contains(fn (PaymentGateway $g) => $g->is_configured);

        if (! $haGateway) {
            throw new RuntimeException('Questo venditore non ha ancora configurato un metodo di pagamento per la quota in euro: riprova più tardi o contattalo direttamente.');
        }
    }

    /**
     * L'indirizzo si compila una volta sola nel profilo, non a ogni acquisto:
     * se manca, si blocca prima di muovere qualsiasi cosa.
     */
    private function assertIndirizzoCompleto(Account $buyerAccount, bool $serveSpedizione): void
    {
        if ($serveSpedizione && ! $buyerAccount->hasShippingAddress()) {
            throw new RuntimeException('Questo prodotto va spedito: prima di acquistarlo, completa il tuo indirizzo di spedizione nella sezione "Indirizzo di spedizione" del tuo profilo.');
        }
    }

    /**
     * Il conto business principale dell'azienda: mai un sottoconto, mai il
     * conto sistema.
     */
    private function contoDelVenditore($company): Account
    {
        return $company->accounts()
            ->where('is_system_account', false)
            ->where('owner_type', 'company')
            ->whereNull('parent_account_id')
            ->firstOrFail();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function descrizione(array $items, int $spedizioneKy, int $spedizioneEuro): string
    {
        $primo = $items[0];
        $listing = $primo['listing'];

        $testo = 'Acquisto shop: ' . $listing->title
            . ($primo['variant'] ? ' (' . $primo['variant']->etichetta_corta . ')' : '')
            . ($primo['quantity'] > 1 ? " (x{$primo['quantity']})" : '')
            . ($listing->is_on_offer ? ' [offerta]' : '');

        $altri = count($items) - 1;
        if ($altri > 0) {
            $testo .= ' + altri ' . $altri . ($altri === 1 ? ' prodotto' : ' prodotti');
        }

        if ($spedizioneKy > 0 || $spedizioneEuro > 0) {
            $testo .= ' + spedizione';
        }

        return $testo;
    }
}
