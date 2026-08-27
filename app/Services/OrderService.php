<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturnRequest;
use App\Models\PaymentGateway;
use App\Models\ShippingAddress;
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
        ?string $buyerNote = null,
        ?ShippingAddress $shippingAddress = null,
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

        return DB::transaction(function () use ($buyerAccount, $user, $righe, $ipAddress, $buyerNote, $shippingAddress) {
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

                // Difesa in profondita' (audit 26/08/2026, 1.5). La soglia sul
                // prezzo minimo vive nel form prodotto e in quello delle
                // offerte, ma non copre tutto: i prodotti caricati PRIMA di
                // quella regola, e un delta di variante che abbassa il prezzo
                // sotto la soglia, arrivano ancora qui con la quota KY
                // arrotondata a zero. Un movimento da zero non e' registrabile
                // e faceva morire l'intero carrello con un messaggio muto:
                // meglio dire subito quale prodotto e' e perche'.
                if ((int) $unitKy <= 0 && (int) $listing->effective_ky_percentage > 0) {
                    throw new RuntimeException(
                        '"' . $listing->title . '" ha un prezzo troppo basso perché la sua quota in KY sia calcolabile: '
                        . 'segnalalo al venditore, per ora non è acquistabile.'
                    );
                }

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

            $this->assertNessunoDeiDueESospeso($buyerAccount, $company);
            $this->assertVenditorePuoIncassareEuro($company, $totaleEuro);
            $this->assertIndirizzoCompleto($buyerAccount, $serveSpedizione, $shippingAddress);

            // L'indirizzo dell'ordine: quello scelto in cassa se c'e', altrimenti
            // il predefinito del conto (che e' la copia tenuta su accounts.*).
            // Da qui in giu' si legge solo $campiSpedizione, mai piu' il conto.
            $campiSpedizione = $this->campiSpedizione($buyerAccount, $serveSpedizione, $shippingAddress);

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
                'shipping_recipient_name' => $campiSpedizione['shipping_recipient_name'],
                'shipping_address'        => $campiSpedizione['shipping_address'],
                'shipping_city'           => $campiSpedizione['shipping_city'],
                'shipping_postal_code'    => $campiSpedizione['shipping_postal_code'],
                'shipping_province'       => $campiSpedizione['shipping_province'],
                'shipping_phone'          => $campiSpedizione['shipping_phone'],
                // Nota lasciata dal compratore in cassa (fase A, 26/08/2026).
                // Snapshot come tutto il resto dell'ordine: se domani il
                // compratore cambia idea, quella che il venditore ha letto
                // resta quella.
                'buyer_note'              => $buyerNote,
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
     * Un'azienda sospesa esce dal commercio, da tutte e due i lati.
     *
     * Decisione di Laura del 26/08/2026: sospendere congela il commercio, non
     * l'accesso. L'azienda continua a entrare nel portale, vede i suoi conti e
     * i suoi movimenti, e puo' onorare gli ordini gia' presi - ma non vende e
     * non compra piu' niente di nuovo. Le quote in euro gia' aperte restano
     * saldabili di proposito: la sospensione ferma il traffico NUOVO, non
     * travolge chi ha gia' pagato in buona fede.
     *
     * Sta QUI, dentro la transazione, perche' e' l'unico punto da cui passano
     * tutte le strade d'acquisto: carrello, "Compra ora" e qualunque cosa
     * verra' dopo. Le guardie piu' in alto servono solo a dirlo prima e meglio.
     */
    private function assertNessunoDeiDueESospeso(Account $buyerAccount, $company): void
    {
        if ($company && $company->isSuspended()) {
            throw new RuntimeException('Questo venditore non è al momento operativo nel circuito: riprova più tardi.');
        }

        $aziendaCompratore = $buyerAccount->company;

        if ($aziendaCompratore && $aziendaCompratore->isSuspended()) {
            throw new RuntimeException('La tua azienda è sospesa: non puoi effettuare acquisti finché la sospensione è attiva. Contatta il supporto.');
        }
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

        // La quota in euro dev'essere incassabile davvero (audit 26/08/2026,
        // 1.4). Stripe rifiuta gli incassi sotto i 50 centesimi: senza questo
        // controllo un ordine da 25 centesimi di quota euro passava l'addebito
        // KY e veniva respinto dopo, restando "in attesa del pagamento in
        // euro" per sempre CON I KY GIA' USCITI. Si blocca qui, che e' prima
        // di Order::create() e prima di book(): non si muove niente.
        $minimo = (int) config('kmoney.shop.min_euro_quota', 50);

        if ($minimo > 0 && $totaleEuro < $minimo) {
            throw new RuntimeException(
                'La quota in euro di questo ordine ('
                . number_format($totaleEuro / 100, 2, ',', '.')
                . ' €) è troppo bassa per essere incassata: il minimo è '
                . number_format($minimo / 100, 2, ',', '.')
                . ' €. Aumenta la quantità oppure scegli un prodotto con una quota in euro più alta.'
            );
        }
    }

    /**
     * L'indirizzo si compila una volta sola nel profilo, non a ogni acquisto:
     * se manca, si blocca prima di muovere qualsiasi cosa.
     */
    private function assertIndirizzoCompleto(Account $buyerAccount, bool $serveSpedizione, ?ShippingAddress $shippingAddress = null): void
    {
        if (! $serveSpedizione) {
            return;
        }

        // Difesa in profondita': un indirizzo di un'altra rubrica non deve
        // poter diventare la destinazione di questo ordine, nemmeno se chi
        // chiama si e' dimenticato di controllarlo.
        if ($shippingAddress !== null && (int) $shippingAddress->account_id !== (int) $buyerAccount->id) {
            throw new RuntimeException('L\'indirizzo scelto non appartiene alla tua rubrica.');
        }

        if ($shippingAddress === null && ! $buyerAccount->hasShippingAddress()) {
            throw new RuntimeException('Questo prodotto va spedito: prima di acquistarlo, completa il tuo indirizzo di spedizione nella sezione "Indirizzo di spedizione" del tuo profilo.');
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function campiSpedizione(Account $buyerAccount, bool $serveSpedizione, ?ShippingAddress $shippingAddress): array
    {
        $vuoti = [
            'shipping_recipient_name' => null,
            'shipping_address'        => null,
            'shipping_city'           => null,
            'shipping_postal_code'    => null,
            'shipping_province'       => null,
            'shipping_phone'          => null,
        ];

        if (! $serveSpedizione) {
            return $vuoti;
        }

        if ($shippingAddress !== null) {
            return $shippingAddress->comeCampiShipping();
        }

        return [
            'shipping_recipient_name' => $buyerAccount->shipping_recipient_name,
            'shipping_address'        => $buyerAccount->shipping_address,
            'shipping_city'           => $buyerAccount->shipping_city,
            'shipping_postal_code'    => $buyerAccount->shipping_postal_code,
            'shipping_province'       => $buyerAccount->shipping_province,
            'shipping_phone'          => $buyerAccount->shipping_phone,
        ];
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

    /**
     * Rimette in magazzino la merce di un ordine dello shop dopo un rimborso,
     * e marca l'ordine come rimborsato (audit 26/08/2026, punto 1.3).
     *
     * Prima non lo faceva nessuno: `refundMerchant` accetta da sempre i
     * movimenti `portal_marketplace_order` fra i rimborsabili, ma restituisce
     * solo i soldi. I pezzi scalati all'acquisto restavano scalati, e dopo
     * qualche reso il venditore risultava esaurito con la merce in mano.
     *
     * SOLO SUL RIMBORSO TOTALE (decisione di Laura, 26/08/2026): su un rimborso
     * parziale non c'e' modo di sapere quanti pezzi siano tornati indietro, e
     * indovinare sarebbe peggio che non fare niente. In quel caso questo metodo
     * non tocca ne' le scorte ne' lo stato.
     *
     * Sta fuori da `TransferBookingService` di proposito: il motore finanziario
     * non deve sapere che esiste uno shop. E sta DOPO il rimborso, non dentro:
     * se questo pezzo fallisce, i soldi sono comunque tornati al compratore -
     * che e' il verso giusto in cui sbagliare.
     *
     * E' idempotente: un ordine gia' `refunded` non viene toccato due volte.
     *
     * @return Order|null l'ordine aggiornato, oppure null se non c'era niente
     *                    da fare (movimento non legato a un ordine dello shop,
     *                    rimborso parziale, o scorte gia' restituite)
     */
    public function ripristinaScorteDopoRimborso(Transfer $originale): ?Order
    {
        if (! $originale->order_id) {
            return null;
        }

        return DB::transaction(function () use ($originale) {
            $order = Order::query()->lockForUpdate()->find($originale->order_id);

            // La domanda vera e' "i pezzi sono gia' tornati?", non "in che
            // stato e' l'ordine". Da quando esistono tre strade per rimborsare
            // (movimenti, annullamento, reso accettato) lo stato non basta
            // piu': un ordine annullato e poi rimborsato di nuovo a mano dai
            // movimenti si vedrebbe restituire la merce due volte.
            if (! $order || $order->scorteGiaRestituite()) {
                return null;
            }

            // Quanto e' stato rimborsato in tutto su questo movimento: la somma
            // di tutti i rimborsi contabilizzati, non solo dell'ultimo. Due
            // rimborsi parziali che insieme coprono il totale contano come un
            // rimborso totale, ed e' giusto cosi'.
            $rimborsato = (int) Transfer::query()
                ->where('reversed_transfer_id', $originale->id)
                ->where('status', 'booked')
                ->sum('amount');

            if ($rimborsato < (int) $originale->amount) {
                return null;
            }

            $this->rimettiInMagazzino($order);

            $order->forceFill([
                'status'            => Order::STATUS_REFUNDED,
                'stock_restored_at' => now(),
            ])->save();

            return $order;
        });
    }

    /**
     * Rimette in magazzino i pezzi di un ordine.
     *
     * Specchio esatto di come le scorte sono state scalate in `place()`: sulla
     * combinazione se c'era, altrimenti sul prodotto, e solo se la scorta e'
     * limitata. NON marca niente: chi lo chiama decide che stato dare
     * all'ordine e quando scrivere `stock_restored_at`. Va chiamato dentro una
     * transazione, con l'ordine gia' bloccato.
     */
    private function rimettiInMagazzino(Order $order): void
    {
        foreach ($order->items as $item) {
            // Specchio esatto di come le scorte sono state scalate in
            // place(): sulla combinazione se c'era, altrimenti sul
            // prodotto, e solo se la scorta e' limitata.
            //
            // `variant_label` e' lo snapshot preso all'acquisto: se la
            // combinazione e' stata cancellata nel frattempo,
            // `listing_variant_id` e' diventato NULL ma l'etichetta resta.
            // Serve a non rimettere sul PRODOTTO dei pezzi che erano stati
            // tolti a una VARIANTE - il magazzino ne uscirebbe gonfiato.
            if ($item->listing_variant_id) {
                $variante = ListingVariant::query()->lockForUpdate()->find($item->listing_variant_id);

                if ($variante && $variante->hasLimitedStock()) {
                    $variante->increment('stock_quantity', $item->quantity);
                }

                continue;
            }

            if ($item->variant_label !== null) {
                continue;
            }

            $listing = Listing::query()->lockForUpdate()->find($item->listing_id);

            if ($listing && $listing->hasLimitedStock()) {
                $listing->increment('stock_quantity', $item->quantity);
            }
        }
    }
    // ── Annullamento e resi: qui i soldi tornano indietro ────────────────
    //
    // Giro 2 della fase B (27/08/2026). Tutto quello che sta sopra questa riga
    // sposta etichette; tutto quello che sta sotto sposta denaro, e infatti ha
    // regole piu' severe: chi puo' farlo, fino a quando, e - la parte nuova -
    // che succede se il venditore i KY non ce li ha piu'.

    /**
     * Annulla un ordine e restituisce i KY al compratore.
     *
     * Lo fanno il VENDITORE e l'ADMIN per conto suo, mai il compratore
     * (decisione di Laura, 27/08: come su WooCommerce, Shopify, PrestaShop).
     * E solo finche' il pacco non e' partito: da "spedito" in poi c'e' il reso.
     *
     * Tutto dentro una transazione sola, al contrario del rimborso emesso dai
     * movimenti. Li' era giusto che le scorte fallissero DOPO i soldi gia'
     * restituiti, perche' il rimborso era il fatto e il resto la conseguenza.
     * Qui il fatto e' l'annullamento nella sua interezza: un ordine segnato
     * "annullato" con i KY ancora sul conto del venditore sarebbe una bugia
     * scritta in tabella, e a rimediare si farebbe piu' danno.
     *
     * @throws RuntimeException con un messaggio gia' pronto per l'utente
     */
    public function annulla(Order $order, User $attore, string $motivo, ?string $ip = null): Order
    {
        return DB::transaction(function () use ($order, $attore, $motivo, $ip) {
            $bloccato = Order::query()->lockForUpdate()->find($order->id);

            if (! $bloccato) {
                throw new RuntimeException('Questo ordine non esiste più.');
            }

            if (! $bloccato->puoEssereAnnullato()) {
                throw new RuntimeException(
                    $bloccato->isRimborsato()
                        ? 'Questo ordine è già stato annullato o rimborsato.'
                        : 'Un ordine già partito non si annulla: il pacco è in viaggio. Se il cliente non lo vuole più, deve aprire un reso quando lo riceve.'
                );
            }

            $rimborso = $this->restituisciIKy(
                $bloccato,
                $attore,
                'Annullamento ordine ' . $bloccato->numero,
                $ip
            );

            $this->rimettiInMagazzino($bloccato);
            $this->chiudiLaQuotaInEuro($bloccato);

            $bloccato->forceFill([
                'status'               => Order::STATUS_CANCELLED,
                'cancelled_at'         => now(),
                'cancel_reason'        => $motivo,
                'cancelled_by_user_id' => $attore->id,
                'stock_restored_at'    => now(),
                'refund_transfer_id'   => $rimborso?->id,
            ])->save();

            return $bloccato;
        });
    }

    /**
     * Il compratore chiede indietro i soldi di un ordine gia' ricevuto.
     *
     * Non muove un centesimo: apre una pratica. E' la differenza che tiene in
     * piedi la fiducia del venditore - nessuno puo' prelevare dal suo conto
     * senza il suo assenso o quello dell'admin.
     *
     * @throws RuntimeException
     */
    public function chiediReso(Order $order, User $compratore, string $motivo): OrderReturnRequest
    {
        return DB::transaction(function () use ($order, $compratore, $motivo) {
            $bloccato = Order::query()->lockForUpdate()->find($order->id);

            if (! $bloccato) {
                throw new RuntimeException('Questo ordine non esiste più.');
            }

            if ($bloccato->resoInCorso() !== null) {
                throw new RuntimeException('Hai già una richiesta di reso aperta su questo ordine: aspetta la risposta del venditore.');
            }

            if (! $bloccato->puoChiedereReso()) {
                $scadenza = $bloccato->scadenzaReso();

                throw new RuntimeException(
                    $scadenza !== null && $scadenza->isPast()
                        ? 'Il tempo per chiedere un reso su questo ordine è scaduto il ' . $scadenza->format('d/m/Y') . '. Puoi comunque scrivere al venditore.'
                        : 'Puoi chiedere un reso solo dopo che l\'ordine è stato spedito.'
                );
            }

            return OrderReturnRequest::create([
                'order_id'             => $bloccato->id,
                'requested_by_user_id' => $compratore->id,
                'status'               => OrderReturnRequest::STATUS_PENDING,
                'reason'               => $motivo,
            ]);
        });
    }

    /**
     * Il venditore (o l'admin per conto suo) accetta il reso: i KY tornano al
     * compratore e la merce rientra a magazzino.
     *
     * L'ordine finisce in `refunded` e non in `cancelled`: sono due storie
     * diverse e fra sei mesi la differenza conta. "Annullato" vuol dire che
     * non e' mai partito niente; "rimborsato" che e' partito, e' arrivato ed
     * e' tornato indietro.
     *
     * @throws RuntimeException
     */
    public function accettaReso(OrderReturnRequest $richiesta, User $attore, ?string $nota, bool $daAdmin = false, ?string $ip = null): Order
    {
        return DB::transaction(function () use ($richiesta, $attore, $nota, $daAdmin, $ip) {
            $pratica = OrderReturnRequest::query()->lockForUpdate()->find($richiesta->id);

            if (! $pratica || ! $pratica->isPending()) {
                throw new RuntimeException('Questa richiesta di reso è già stata chiusa.');
            }

            $order = Order::query()->lockForUpdate()->find($pratica->order_id);

            if (! $order) {
                throw new RuntimeException('L\'ordine di questa richiesta non esiste più.');
            }

            $rimborso = $this->restituisciIKy(
                $order,
                $attore,
                'Reso accettato, ordine ' . $order->numero,
                $ip
            );

            if (! $order->scorteGiaRestituite()) {
                $this->rimettiInMagazzino($order);
            }

            $this->chiudiLaQuotaInEuro($order);

            $order->forceFill([
                'status'             => Order::STATUS_REFUNDED,
                'stock_restored_at'  => $order->stock_restored_at ?? now(),
                'refund_transfer_id' => $rimborso?->id ?? $order->refund_transfer_id,
            ])->save();

            $pratica->forceFill([
                'status'             => OrderReturnRequest::STATUS_ACCEPTED,
                'decided_by_user_id' => $attore->id,
                'decided_at'         => now(),
                'decided_by_admin'   => $daAdmin,
                'decision_note'      => $nota,
            ])->save();

            return $order;
        });
    }

    /**
     * Il venditore rifiuta il reso. Nessun soldo si muove, ma il perche' e'
     * obbligatorio: un rifiuto senza motivo e' il modo migliore di perdere un
     * cliente e di far arrivare la lite all'assistenza del circuito.
     *
     * @throws RuntimeException
     */
    public function rifiutaReso(OrderReturnRequest $richiesta, User $attore, string $nota, bool $daAdmin = false): OrderReturnRequest
    {
        return DB::transaction(function () use ($richiesta, $attore, $nota, $daAdmin) {
            $pratica = OrderReturnRequest::query()->lockForUpdate()->find($richiesta->id);

            if (! $pratica || ! $pratica->isPending()) {
                throw new RuntimeException('Questa richiesta di reso è già stata chiusa.');
            }

            $pratica->forceFill([
                'status'             => OrderReturnRequest::STATUS_REJECTED,
                'decided_by_user_id' => $attore->id,
                'decided_at'         => now(),
                'decided_by_admin'   => $daAdmin,
                'decision_note'      => $nota,
            ])->save();

            return $pratica;
        });
    }

    /**
     * Restituisce al compratore i KY ancora non rimborsati di questo ordine.
     *
     * Sta qui e non nel motore finanziario di proposito: `refundMerchant()`
     * sa muovere i soldi ma non sa niente di ordini, di scorte e di negozi -
     * e deve continuare a non saperlo.
     *
     * LA REGOLA DEL FIDO (decisione di Laura, 27/08). `refundMerchant()` non
     * guarda se il venditore i KY ce li ha: toglie l'importo e basta, e un
     * conto puo' finire sotto zero senza limite. Nei pagamenti normali il
     * circuito questo controllo lo fa da sempre - `assertTransferWithinLimits`
     * non lascia scendere sotto il fido concesso - ma i rimborsi non ci
     * passano. Allora la stessa regola la applichiamo qui, con lo stesso
     * metro gia' usato dal resto del circuito: `saldoDisponibile()`, cioe'
     * saldo piu' fido. Fin li' il rimborso parte anche in negativo; oltre, si
     * ferma e si spiega quanto manca.
     *
     * @return Transfer|null il movimento di rimborso, o null se non c'era
     *                       niente da restituire (ordine senza movimento KY,
     *                       o gia' rimborsato per intero altrove)
     *
     * @throws RuntimeException
     */
    private function restituisciIKy(Order $order, User $attore, string $descrizione, ?string $ip): ?Transfer
    {
        $movimento = $order->transfer()->first();

        if (! $movimento || $movimento->status !== 'booked') {
            return null;
        }

        $giaRimborsato = (int) Transfer::query()
            ->where('reversed_transfer_id', $movimento->id)
            ->where('status', 'booked')
            ->sum('amount');

        $residuo = (int) $movimento->amount - $giaRimborsato;

        if ($residuo <= 0) {
            return null;
        }

        $venditore = $order->sellerAccount()->first();

        if (! $venditore) {
            throw new RuntimeException('Il conto del venditore di questo ordine non è più raggiungibile: serve l\'assistenza del circuito.');
        }

        // Il controllo dei permessi lo rifa' anche `refundMerchant()`, ma con
        // un messaggio da motore finanziario. Qui si sa di che si sta
        // parlando, e chi legge deve capire cosa fare.
        if (! $attore->is_super_admin && ! $attore->canSendFromAccount($venditore)) {
            throw new RuntimeException('Non hai il permesso di muovere denaro dal conto di questo negozio: serve il permesso "pagamenti" oppure un profilo di super admin.');
        }

        $venditore->refresh();
        $disponibile = $venditore->saldoDisponibile();

        if ($disponibile < $residuo) {
            $manca = $residuo - max(0, $disponibile);

            throw new RuntimeException(
                'Per restituire ' . ky_format($residuo) . ' KY al cliente servono '
                . ky_format($manca) . ' KY in più di quelli disponibili (saldo più fido). '
                . 'Ricarica il conto o chiedi un aumento del fido, poi riprova.'
            );
        }

        return $this->bookingService->refundMerchant(
            originalTransfer: $movimento,
            refundAmount:     $residuo,
            initiatedBy:      $attore->id,
            description:      $descrizione,
            ipAddress:        $ip,
        );
    }

    /**
     * Chiude la quota in euro ancora da incassare.
     *
     * Solo quella NON pagata: se il venditore l'ha gia' incassata, questi
     * soldi non sono mai passati dal circuito e nessuno qui dentro puo'
     * restituirli. In quel caso la riga resta "pagata" e sara' il messaggio a
     * ricordare al venditore che deve fare un bonifico - meglio un promemoria
     * scomodo di un dato falso in tabella.
     */
    private function chiudiLaQuotaInEuro(Order $order): void
    {
        $quota = $order->payment()->first();

        if (! $quota) {
            return;
        }

        if (in_array($quota->status, [
            MarketplaceOrderPayment::STATUS_PENDING,
            MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION,
        ], true)) {
            $quota->forceFill(['status' => MarketplaceOrderPayment::STATUS_CANCELLED])->save();
        }
    }
}
