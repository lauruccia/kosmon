<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * TEST DI REGRESSIONE — le difese dell'acquisto shop (fase A, 25/08/2026).
 *
 * ShopPurchaseRegressionTest (fase 0a) descrive l'acquisto quando va a buon
 * fine. Questo file descrive tutto il resto: cosa succede quando NON deve
 * andare a buon fine, e quali regole contabili devono valere comunque.
 *
 * Perché adesso: stiamo per introdurre il carrello (PIANO_CARRELLO_VARIANTI.md).
 * Il carrello prende un acquisto che oggi chiama TransferBookingService::book()
 * UNA volta e lo trasforma in N chiamate dentro la stessa transazione. Ogni
 * riga qui sotto è un comportamento che dopo quel cambio deve essere ancora
 * identico — e questi test devono passare senza essere ritoccati, altrimenti
 * vuol dire che il carrello ha cambiato qualcosa che non doveva cambiare.
 *
 * Importi sempre in centesimi di KY (5000 = 50,00 KY).
 */
class ShopPurchaseGuardsTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. Le regole contabili che nessuna funzione dello shop può violare
    // =========================================================================

    public function test_l_acquisto_non_crea_e_non_distrugge_denaro(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $prima = $this->sommaSaldiCircuito();

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $this->assertSame(
            $prima,
            $this->sommaSaldiCircuito(),
            'Un acquisto sposta denaro fra due conti: la somma di TUTTI i saldi del circuito non deve cambiare.'
        );
    }

    public function test_un_acquisto_fallito_lascia_i_saldi_esattamente_come_li_ha_trovati(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 1000);   // 10,00 KY
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100); // 50,00 KY

        $prima = $this->sommaSaldiCircuito();

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $this->assertSame(1000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $sellerAccount->fresh()->available_balance);
        $this->assertSame($prima, $this->sommaSaldiCircuito());
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(0, MarketplaceOrderPayment::count());
    }

    public function test_ogni_movimento_di_acquisto_ha_due_righe_di_registro_di_pari_importo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $entries  = $transfer->ledgerEntries()->get();

        $dare  = (int) $entries->where('direction', 'debit')->sum('amount');
        $avere = (int) $entries->where('direction', 'credit')->sum('amount');

        $this->assertCount(2, $entries, 'Partita doppia: un movimento, due righe di registro.');
        $this->assertSame($dare, $avere, 'La somma dei dare deve essere uguale alla somma degli avere.');
        $this->assertSame(5000, $dare);
    }

    // =========================================================================
    // 2. Saldo, limiti e tracciamento dei tentativi
    //
    //    ATTENZIONE — DIFETTO NOTO messo per iscritto qui, non un comportamento
    //    desiderato (scoperto il 25/08/2026 scrivendo questi test).
    //
    //    TransferBookingService::book() registra ogni tentativo rifiutato in
    //    AuditLog con l'evento `transfer.rejected`, di proposito FUORI dalla
    //    transazione fallita "così il log viene sempre persistito". Ma
    //    ListingController::buy() chiama book() dentro una PROPRIA
    //    DB::transaction(), e quando quella va in rollback si porta via anche
    //    il log. Risultato: sullo shop i tentativi falliti non vengono contati,
    //    e il blocco automatico anti-frode (3 fallimenti in 5 minuti = conto
    //    bloccato 30 minuti) non scatta mai — a differenza di ogni altro canale
    //    di pagamento del portale.
    //
    //    Non viene corretto qui: la fase A non tocca il codice applicativo. I
    //    due test seguenti fissano il comportamento di oggi, così quando la
    //    fase B sposterà la transazione dentro OrderService si vedrà subito se
    //    il difetto è stato risolto (i test diventeranno rossi, ed è il momento
    //    di aggiornarli) oppure trascinato dentro il carrello.
    // =========================================================================

    public function test_saldo_insufficiente_blocca_l_acquisto_senza_muovere_nulla(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 1000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());

        // Vedi il blocco di commento qui sopra: il log del tentativo esiste, ma
        // il rollback della transazione del controller lo cancella.
        $this->assertSame(
            0,
            AuditLog::query()->where('event', 'transfer.rejected')->count(),
            'Oggi il tentativo rifiutato NON resta tracciato: se questo test diventa rosso, il difetto è stato corretto.'
        );
    }

    public function test_il_blocco_automatico_anti_frode_oggi_non_scatta_sugli_acquisti_shop(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 1000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);
        }

        $this->assertNull(
            $buyerAccount->fresh()->locked_until,
            'Oggi quattro acquisti falliti di fila non bloccano il conto, perché i tentativi non vengono contati.'
        );
        $this->assertFalse($buyerAccount->fresh()->isTemporarilyLocked());
        $this->assertSame(1000, $buyerAccount->fresh()->available_balance);
    }

    public function test_un_prodotto_sopra_il_limite_per_singolo_movimento_non_si_puo_comprare(): void
    {
        // Il default globale è 200.000 centesimi = 2.000,00 KY per movimento.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 500000);
        [$company, , $sellerAccount] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 250000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            // Il messaggio conta quanto l'effetto: senza questa asserzione il
            // test resterebbe verde anche se a bloccare l'acquisto fosse un
            // limite completamente diverso (verificato con una mutazione).
            ->assertSessionHas('portal_error', fn ($errore) => str_contains(
                (string) $errore,
                'limite massimo per singola operazione'
            ));

        $this->assertSame(500000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $sellerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
    }

    // =========================================================================
    // 3. Lo stato commerciale del venditore
    // =========================================================================

    public function test_azienda_venditrice_in_debito_vende_solo_al_cento_per_cento_ky(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $this->makeGateway($company);

        // 50,00 KY, metà in KY e metà in euro.
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 50);

        // Il venditore va in rosso: l'hook su Account::saved riscrive il
        // catalogo (Account::syncListingsKyPercentage), salvando la percentuale
        // desiderata per poterla ripristinare quando il saldo rientra.
        $sellerAccount->forceFill(['available_balance' => -1000])->save();

        $listing->refresh();
        $this->assertSame(100, (int) $listing->ky_percentage);
        $this->assertSame(50, (int) $listing->desired_ky_percentage);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        // Tutto in KY: nessuna quota in euro da saldare fuori dal circuito.
        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(5000, (int) $transfer->amount);
        $this->assertSame(0, MarketplaceOrderPayment::count());
        $this->assertSame(95000, $buyerAccount->fresh()->available_balance);
    }

    public function test_azienda_venditrice_sospesa_non_puo_incassare(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $company->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error', fn ($errore) => str_contains(
                (string) $errore,
                'destinataria non è attiva nel circuito'
            ));

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
    }

    public function test_il_venditore_incassa_sul_conto_business_principale_non_su_un_sottoconto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);

        // Un sottoconto (es. il conto di un reparto o di un gestore): nasce a
        // saldo 0 e non deve mai essere il destinatario di un ordine shop.
        $sottoconto = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
            'is_system_account' => false,
            'parent_account_id' => $sellerAccount->id,
        ]);

        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame($sellerAccount->id, (int) $transfer->to_account_id);
        $this->assertSame(5000, $sellerAccount->fresh()->available_balance);
        $this->assertSame(0, $sottoconto->fresh()->available_balance);
    }

    // =========================================================================
    // 4. La quota in euro
    // =========================================================================

    public function test_la_quota_in_euro_conta_la_quantita_ma_una_sola_spedizione(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $this->makeGateway($company);

        // 20,00 KY al 50%, spedizione 5,00 (anch'essa divisa al 50%).
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 50, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 500,
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 3]);

        // KY:  (1000 x 3) + 250 = 3250     EUR: (1000 x 3) + 250 = 3250
        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(3250, (int) $transfer->amount);
        $this->assertSame(3250, $sellerAccount->fresh()->available_balance);

        $payment = MarketplaceOrderPayment::query()->sole();
        $this->assertSame(3250, (int) $payment->amount);
        $this->assertSame(MarketplaceOrderPayment::STATUS_PENDING, $payment->status);
        $this->assertSame($company->id, (int) $payment->company_id);
        $this->assertSame($transfer->id, (int) $payment->transfer_id);
    }

    public function test_la_quota_in_euro_e_agganciata_al_movimento_uno_a_uno(): void
    {
        // Vincolo di schema che il carrello dovrà rimuovere (fase B): oggi
        // marketplace_order_payments.transfer_id è UNIQUE, quindi un movimento
        // non può avere due pagamenti in euro. Questo test lo mette per
        // iscritto: quando in fase B cadrà, deve cadere di proposito.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $payment = MarketplaceOrderPayment::query()->sole();

        $this->expectException(\Illuminate\Database\QueryException::class);

        MarketplaceOrderPayment::create([
            'transfer_id' => $payment->transfer_id,
            'listing_id'  => $payment->listing_id,
            'company_id'  => $payment->company_id,
            'amount'      => 100,
            'status'      => MarketplaceOrderPayment::STATUS_PENDING,
        ]);
    }

    // =========================================================================
    // 5. Quantità e doppio invio
    // =========================================================================

    public function test_quantita_zero_o_negativa_e_rifiutata(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 0])
            ->assertSessionHasErrors('quantity');

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => -3])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
    }

    public function test_due_invii_dello_stesso_acquisto_creano_due_ordini_distinti(): void
    {
        // Comportamento ATTUALE, non necessariamente desiderabile: ogni POST è
        // un ordine nuovo, con una idempotency key nuova. Messo per iscritto
        // perché il checkout del carrello (fase C) dovrà decidere se tenerlo
        // così o proteggere il doppio clic — e questo test dirà se è cambiato.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);
        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $this->assertSame(2, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(90000, $buyerAccount->fresh()->available_balance);
    }
}
