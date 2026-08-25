<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\Transfer;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE C — il carrello (PIANO_CARRELLO_VARIANTI.md).
 *
 * La cosa che questi test difendono più di ogni altra: **alla cassa, o passano
 * tutti gli ordini o non ne passa nessuno**. Un carrello con tre venditori che
 * paga i primi due e si ferma sul terzo sarebbe il peggior difetto possibile di
 * questa funzione, perché lascerebbe l'acquirente con dei KY spesi e un ordine
 * che non esiste.
 *
 * Importi in CENTESIMI.
 */
class CartPhaseCTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. Riempire il carrello
    // =========================================================================

    public function test_aggiungere_un_prodotto_lo_mette_nel_carrello_del_conto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing), ['quantity' => 2])
            ->assertSessionHas('portal_success');

        $cart = Cart::attivoPer($buyerAccount);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(2, (int) $cart->items()->sole()->quantity);

        // Nessun movimento: mettere nel carrello non è comprare.
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_aggiungere_due_volte_lo_stesso_prodotto_somma_le_quantita(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);
        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 3]);

        $cart = Cart::attivoPer($buyerAccount);
        $this->assertSame(1, $cart->items()->count(), 'Lo stesso prodotto non deve comparire due volte.');
        $this->assertSame(5, (int) $cart->items()->sole()->quantity);
    }

    public function test_non_si_puo_mettere_nel_carrello_un_prodotto_della_propria_azienda(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($sellerUser)
            ->post(route('portal.cart.add', $listing))
            ->assertSessionHas('portal_error');

        $this->assertSame(0, CartItem::count());
    }

    public function test_non_si_puo_mettere_nel_carrello_piu_di_quanto_ce_ne_sia(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 3]);

        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing), ['quantity' => 5])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, CartItem::count());
    }

    public function test_il_carrello_appartiene_al_conto_non_al_browser(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        // Un'altra sessione, stesso utente: il carrello è ancora lì.
        $this->flushSession();

        $this->actingAs($buyer)->get(route('portal.cart'))->assertOk()->assertSee($listing->title);
        $this->assertSame(1, Cart::attivoPer($buyerAccount)->totalePezzi());
    }

    public function test_nessuno_puo_toccare_le_righe_del_carrello_di_un_altro(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$estraneo] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $riga = CartItem::query()->sole();

        $this->actingAs($estraneo)
            ->delete(route('portal.cart.item.remove', $riga))
            ->assertSessionHas('portal_error');

        $this->assertSame(1, CartItem::count(), 'La riga di un altro non si tocca.');
    }

    // =========================================================================
    // 2. Il prezzo non si congela nel carrello
    // =========================================================================

    public function test_il_carrello_segue_il_prezzo_di_adesso_non_quello_di_quando_lo_hai_riempito(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);
        $this->assertSame(4000, Cart::attivoPer($buyerAccount)->totaleKy());

        // Parte l'offerta della settimana mentre il carrello è fermo.
        \App\Models\ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 2000,
            'offer_price_ky'         => 1000,
            'offer_ky_percentage'    => 100,
            'expires_at'             => now()->addDays(3),
        ]);

        $cart = Cart::attivoPer($buyerAccount->fresh());
        $cart->load('items.listing.activeOffer');

        $this->assertSame(
            2000,
            $cart->totaleKy(),
            'Il carrello non congela i prezzi: chi arriva alla cassa paga quello che vede oggi.'
        );
    }

    // =========================================================================
    // 3. La cassa
    // =========================================================================

    public function test_la_cassa_divide_il_carrello_in_un_ordine_per_venditore(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda, , $primoConto] = $this->makeSeller(saldo: 0);
        [$secondaAzienda, , $secondoConto] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100);
        $due = $this->makeListing($primaAzienda, prezzo: 1000, kyPercentage: 100);
        $tre = $this->makeListing($secondaAzienda, prezzo: 3000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $uno), ['quantity' => 2]); // 40,00
        $this->actingAs($buyer)->post(route('portal.cart.add', $due));                    // 10,00
        $this->actingAs($buyer)->post(route('portal.cart.add', $tre));                    // 30,00

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_success');

        // Due venditori, due ordini, due movimenti.
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Transfer::where('kind', 'portal_marketplace_order')->count());

        $ordinePrimo = Order::where('company_id', $primaAzienda->id)->sole();
        $this->assertSame(5000, $ordinePrimo->total_ky);
        $this->assertCount(2, $ordinePrimo->items()->get(), 'Le due righe dello stesso venditore stanno in un ordine solo.');

        $ordineSecondo = Order::where('company_id', $secondaAzienda->id)->sole();
        $this->assertSame(3000, $ordineSecondo->total_ky);

        // Ogni venditore incassa il suo, l'acquirente paga una volta sola.
        $this->assertSame(5000, $primoConto->fresh()->available_balance);
        $this->assertSame(3000, $secondoConto->fresh()->available_balance);
        $this->assertSame(92000, $buyerAccount->fresh()->available_balance);

        // Il carrello si chiude e il prossimo parte vuoto.
        $this->assertSame(Cart::STATUS_ORDERED, Cart::query()->latest('id')->first()->status);
        $this->assertTrue(Cart::attivoPer($buyerAccount->fresh())->isVuoto());
    }

    public function test_alla_cassa_il_denaro_non_si_crea_e_non_si_distrugge(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller(saldo: 0);
        [$secondaAzienda] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100);
        $due = $this->makeListing($secondaAzienda, prezzo: 3000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $uno));
        $this->actingAs($buyer)->post(route('portal.cart.add', $due));

        $prima = $this->sommaSaldiCircuito();
        $this->actingAs($buyer)->post(route('portal.cart.checkout'));

        $this->assertSame($prima, $this->sommaSaldiCircuito());
    }

    public function test_se_un_venditore_non_puo_incassare_non_paga_nessuno(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$buona, , $contoBuono] = $this->makeSeller(saldo: 0);
        [$problematica, , $contoProblematico] = $this->makeSeller(saldo: 0);

        $ok = $this->makeListing($buona, prezzo: 2000, kyPercentage: 100);
        // Mix KY/EUR ma senza nessun metodo di pagamento configurato: è il
        // controllo che OrderService fa prima di addebitare.
        $ko = $this->makeListing($problematica, prezzo: 3000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.cart.add', $ok));
        $this->actingAs($buyer)->post(route('portal.cart.add', $ko));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count(), 'Nemmeno l\'ordine del venditore a posto deve passare.');
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $contoBuono->fresh()->available_balance);
        $this->assertSame(0, $contoProblematico->fresh()->available_balance);

        // E il carrello resta lì, con dentro tutto: si sistema e si riprova.
        $this->assertSame(2, Cart::attivoPer($buyerAccount->fresh())->items()->count());
    }

    public function test_il_messaggio_di_errore_dice_di_quale_venditore_si_tratta(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$buona] = $this->makeSeller(saldo: 0);
        [$problematica] = $this->makeSeller(saldo: 0);

        $ok = $this->makeListing($buona, prezzo: 2000, kyPercentage: 100);
        $ko = $this->makeListing($problematica, prezzo: 3000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.cart.add', $ok));
        $this->actingAs($buyer)->post(route('portal.cart.add', $ko));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error', fn ($errore) => str_contains((string) $errore, $problematica->name));
    }

    public function test_saldo_insufficiente_sul_totale_blocca_prima_di_pagare_chiunque(): void
    {
        // Il saldo basterebbe per il primo venditore ma non per tutti e due:
        // è il caso che rende obbligatorio il controllo sul TOTALE.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 3000);
        [$primaAzienda, , $primoConto] = $this->makeSeller(saldo: 0);
        [$secondaAzienda, , $secondoConto] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100);
        $due = $this->makeListing($secondaAzienda, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $uno));
        $this->actingAs($buyer)->post(route('portal.cart.add', $due));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'Saldo insufficiente'));

        $this->assertSame(3000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $primoConto->fresh()->available_balance, 'Il primo venditore non deve aver incassato niente.');
        $this->assertSame(0, $secondoConto->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    public function test_un_prodotto_diventato_indisponibile_blocca_la_cassa(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);

        // Il venditore sospende il prodotto mentre è nel carrello.
        $listing->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_la_cassa_di_un_carrello_vuoto_non_fa_danni(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_la_spedizione_si_paga_una_volta_per_venditore_non_per_carrello(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller(saldo: 0);
        [$secondaAzienda] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 500,
        ]);
        $due = $this->makeListing($primaAzienda, prezzo: 1000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 300,
        ]);
        $tre = $this->makeListing($secondaAzienda, prezzo: 3000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 700,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $uno));
        $this->actingAs($buyer)->post(route('portal.cart.add', $due));
        $this->actingAs($buyer)->post(route('portal.cart.add', $tre));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'));

        // Primo venditore: 2000 + 1000 + UNA spedizione (la più cara, 500).
        $this->assertSame(3500, Order::where('company_id', $primaAzienda->id)->sole()->total_ky);
        // Secondo venditore: 3000 + la sua spedizione, 700.
        $this->assertSame(3700, Order::where('company_id', $secondaAzienda->id)->sole()->total_ky);
    }

    public function test_venditori_diversi_vogliono_dire_spedizioni_diverse(): void
    {
        // Regola ribadita da Laura il 25/08: una spedizione per VENDITORE, non
        // una per carrello. Sono pacchi diversi, partono da magazzini diversi.
        // Nello stesso ordine invece si paga una volta sola, anche con dieci
        // prodotti dentro.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller(saldo: 0);
        [$secondaAzienda] = $this->makeSeller(saldo: 0);

        $a1 = $this->makeListing($primaAzienda, prezzo: 1000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE, 'shipping_cost' => 1000,
        ]);
        $a2 = $this->makeListing($primaAzienda, prezzo: 1000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE, 'shipping_cost' => 1000,
        ]);
        $b1 = $this->makeListing($secondaAzienda, prezzo: 1000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE, 'shipping_cost' => 1000,
        ]);

        // Due prodotti dal primo venditore, uno dal secondo.
        $this->actingAs($buyer)->post(route('portal.cart.add', $a1));
        $this->actingAs($buyer)->post(route('portal.cart.add', $a2));
        $this->actingAs($buyer)->post(route('portal.cart.add', $b1));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'));

        // 3 prodotti da 10,00 = 30,00, più DUE spedizioni da 10,00 (non tre,
        // non una): 50,00 in tutto.
        $this->assertSame(5000, (int) Order::query()->sum('total_ky'));
        $this->assertSame(2000, (int) Order::query()->sum('shipping_ky'), 'Due venditori, due spedizioni.');
        $this->assertSame(1000, Order::where('company_id', $primaAzienda->id)->sole()->shipping_ky, 'Due prodotti dallo stesso venditore: una spedizione sola.');
        $this->assertSame(1000, Order::where('company_id', $secondaAzienda->id)->sole()->shipping_ky);
    }

    public function test_il_totale_mostrato_nel_carrello_e_esattamente_quello_che_si_paga(): void
    {
        // La spedizione viene calcolata in DUE posti: Cart::perVenditore() per
        // il totale mostrato a schermo e per il controllo del saldo, e
        // OrderService per quello che viene davvero addebitato. Sono due strade
        // che devono arrivare allo stesso numero: se divergono, l'utente vede
        // un prezzo e ne paga un altro — il modo più rapido per perdere la
        // fiducia di chi compra. Questo test tiene insieme le due strade.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller(saldo: 0);
        [$secondaAzienda] = $this->makeSeller(saldo: 0);
        $this->makeGateway($secondaAzienda);

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 500,
        ]);
        $due = $this->makeListing($primaAzienda, prezzo: 1000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 300,
        ]);
        $tre = $this->makeListing($secondaAzienda, prezzo: 4000, kyPercentage: 50, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 700,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $uno), ['quantity' => 2]);
        $this->actingAs($buyer)->post(route('portal.cart.add', $due));
        $this->actingAs($buyer)->post(route('portal.cart.add', $tre));

        $cart = Cart::attivoPer($buyerAccount->fresh());
        $cart->load('items.listing.company', 'items.listing.activeOffer');
        $totaleMostratoKy  = $cart->totaleKy();
        $totaleMostratoEur = $cart->totaleEuro();

        $this->actingAs($buyer)->post(route('portal.cart.checkout'));

        $this->assertSame(
            $totaleMostratoKy,
            (int) Order::query()->sum('total_ky'),
            'Il totale KY scritto nel carrello deve essere esattamente quello addebitato.'
        );
        $this->assertSame(
            $totaleMostratoEur,
            (int) Order::query()->sum('total_eur'),
            'Idem per la quota in euro.'
        );
    }

    public function test_con_una_sola_quota_in_euro_si_va_dritti_a_pagarla(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 4000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'));

        $payment = MarketplaceOrderPayment::query()->sole();
        $this->assertSame(2000, (int) $payment->amount);

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))  // carrello ormai vuoto
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::query()->sole()->status);
    }

    public function test_svuotare_il_carrello_lo_lascia_vuoto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 3]);
        $this->actingAs($buyer)->post(route('portal.cart.clear'));

        $this->assertTrue(Cart::attivoPer($buyerAccount->fresh())->isVuoto());
    }

    public function test_mettere_a_zero_la_quantita_toglie_la_riga(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 3]);
        $riga = CartItem::query()->sole();

        $this->actingAs($buyer)->patch(route('portal.cart.item.update', $riga), ['quantity' => 0]);

        $this->assertTrue(Cart::attivoPer($buyerAccount->fresh())->isVuoto());
    }

    // =========================================================================
    // 4. Il carrello scaduto
    // =========================================================================

    public function test_un_carrello_scaduto_viene_chiuso_e_se_ne_apre_uno_nuovo(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $vecchio = Cart::attivoPer($buyerAccount);

        // Passano più di trenta giorni.
        $vecchio->forceFill(['expires_at' => now()->subDay()])->save();

        $nuovo = Cart::attivoPer($buyerAccount->fresh());

        $this->assertNotSame($vecchio->id, $nuovo->id);
        $this->assertSame(Cart::STATUS_EXPIRED, $vecchio->fresh()->status);
        $this->assertTrue($nuovo->isVuoto(), 'Un carrello di due mesi fa non è un carrello, è un ricordo.');
    }

    // =========================================================================
    // 5. Il carrello si raggiunge da tutto il portale
    // =========================================================================

    public function test_il_menu_mostra_il_carrello_col_numero_dei_pezzi(): void
    {
        // La voce "Carrello" nel menu segue la stessa condizione della voce
        // "Shop" (permesso marketplace): qui serve quindi un utente che il menu
        // dello shop lo vede davvero.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        $this->abilitaMarketplace($buyer);

        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 3]);

        // Il conteggio arriva da un view composer, quindi compare su QUALSIASI
        // pagina del portale, non solo nello shop.
        $html = $this->actingAs($buyer)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('portal.cart'), $html, 'Il menu deve portare al carrello.');
        $this->assertStringContainsString('<span class="nav-count">3</span>', $html, 'Con tre pezzi dentro, il numerino deve dire 3.');
    }

    public function test_senza_niente_nel_carrello_il_numerino_non_compare(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        $this->abilitaMarketplace($buyer);

        $html = $this->actingAs($buyer)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('portal.cart'), $html);
        // Attenzione: la parola "nav-count" da sola compare sempre, e' anche il
        // nome della classe nel foglio di stile del layout. Si cerca il markup.
        $this->assertStringNotContainsString('<span class="nav-count">', $html, 'Carrello vuoto: nessun numerino.');
    }

    public function test_l_icona_del_carrello_e_in_alto_su_ogni_pagina(): void
    {
        // L'icona sta nella barra in alto, accanto a campanella e tema: e'
        // l'unico punto visibile da qualunque pagina del portale. Se sparisse
        // da li', il carrello diventerebbe di nuovo irraggiungibile mentre si
        // guardano i prodotti — che e' esattamente il difetto segnalato da
        // Laura il 25/08.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);

        foreach ([route('portal.shop.show', $listing), route('portal.shop'), route('portal.dashboard')] as $pagina) {
            $html = $this->actingAs($buyer)->get($pagina)->assertOk()->getContent();

            $this->assertStringContainsString('class="cart-bell"', $html, "Icona carrello assente su {$pagina}");
            $this->assertStringContainsString('<span class="notif-badge">2</span>', $html, "Numerino assente su {$pagina}");
        }
    }

    public function test_l_icona_c_e_anche_senza_il_permesso_marketplace(): void
    {
        // Scelta esplicita: l'icona non dipende dal permesso `marketplace.buy`.
        // Chi non compra trovera' un carrello vuoto, mentre nasconderla a chi
        // invece serve e' l'errore piu' costoso dei due — ed e' successo il
        // 25/08, quando la prima versione la mostrava solo a chi aveva il
        // permesso e Laura non la vedeva.
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('class="cart-bell"', $html);
    }

    // =========================================================================
    // 6. "Compra ora" continua a esistere e a funzionare
    // =========================================================================

    public function test_compra_ora_funziona_ancora_e_non_tocca_il_carrello(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $nelCarrello = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $subito      = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $nelCarrello), ['quantity' => 2]);
        $this->actingAs($buyer)->post(route('portal.shop.buy', $subito));

        // L'acquisto diretto è passato...
        $this->assertSame(1, Order::count());
        $this->assertSame(2000, Order::query()->sole()->total_ky);

        // ...e quello che era nel carrello è ancora lì, intatto.
        $cart = Cart::attivoPer($buyerAccount->fresh());
        $this->assertSame(2, $cart->totalePezzi());
        $this->assertSame(Cart::STATUS_ACTIVE, $cart->status);
    }
}
