<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Transfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * BLOCCO 3 dell'audit del 26/08/2026 — "Compra ora" entra nella cassa.
 *
 * Fino a ieri la pagina del prodotto pagava da sola, con un `confirm()` del
 * browser come unica conferma: niente spunta sulle condizioni di vendita,
 * niente scelta dell'indirizzo, niente pagina "grazie". Erano due esperienze
 * diverse sullo stesso negozio, e quella più usata era la peggiore.
 *
 * Quello che questi test difendono, in ordine di importanza:
 *   1. **dalla pagina prodotto non escono più soldi** — ci vuole la cassa;
 *   2. senza spunta sulle condizioni non si paga, mai;
 *   3. "Compra ora" **non tocca il carrello** di chi ce l'ha già pieno.
 *
 * Importi in CENTESIMI.
 */
class CompraOraInCassaTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. La pagina prodotto non paga più
    // =========================================================================

    public function test_la_pagina_prodotto_non_ha_piu_un_form_che_paga(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        // Il bottone "Acquista" porta alla cassa, non all'addebito.
        $this->assertStringContainsString(route('portal.shop.buy.form', $listing), $html);

        // E il confirm() del browser non c'è più su nessun bottone d'acquisto:
        // era quello che su mobile poteva essere soppresso, trasformando un
        // tocco in un addebito.
        $this->assertStringNotContainsString("Confermi l'acquisto", $html);
    }

    public function test_il_bottone_acquista_apre_la_cassa_col_prodotto_dentro(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Zaino da trekking']);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', ['listing' => $listing->id, 'quantity' => 2]))
            ->assertOk()
            ->assertSee('Zaino da trekking')
            ->assertSee('Conferma')
            // La spunta sulle condizioni è lì, come nella cassa del carrello.
            ->assertSee('accetto_condizioni', false);
    }

    public function test_aprire_la_cassa_non_muove_un_centesimo(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->get(route('portal.shop.buy.form', $listing))->assertOk();

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    public function test_il_post_dal_form_prodotto_rimbalza_in_get_sulla_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        // Il bottone "Acquista" vive nel form del carrello, quindi arriva in
        // POST. Deve rimbalzare in GET: così il token CSRF non finisce nell'URL
        // e un F5 sulla cassa non richiede di reinviare i dati.
        $this->actingAs($buyer)
            ->post(route('portal.shop.buy.form', $listing), ['quantity' => 3])
            ->assertRedirect(route('portal.shop.buy.form', ['listing' => $listing->id, 'quantity' => 3]));
    }

    // =========================================================================
    // 2. Senza spunta non si paga
    // =========================================================================

    public function test_senza_la_spunta_sulle_condizioni_non_si_paga(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing))
            ->assertSessionHasErrors('accetto_condizioni');

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance,
            'Questo è il punto: senza consenso esplicito i KY non si muovono.');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
    }

    public function test_con_la_spunta_l_acquisto_va_a_buon_fine_e_finisce_sulla_pagina_grazie(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 2])
            ->assertRedirectContains(route('portal.cart.thanks'));

        $ordine = Order::sole();
        $this->assertSame(4000, (int) $ordine->total_ky);
        $this->assertSame(96000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(4000, (int) $sellerAccount->fresh()->available_balance);
    }

    // =========================================================================
    // 3. Il carrello resta dov'è
    // =========================================================================

    public function test_compra_ora_non_tocca_il_carrello_gia_pieno(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $nelCarrello = $this->makeListing($company, prezzo: 3000, kyPercentage: 100);
        $alVolo      = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $nelCarrello), ['quantity' => 3]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $alVolo), ['accetto_condizioni' => '1'])
            ->assertRedirectContains(route('portal.cart.thanks'));

        // Ha pagato SOLO il prodotto comprato al volo.
        $this->assertSame(1000, (int) Order::sole()->total_ky);
        $this->assertSame(99000, (int) $buyerAccount->fresh()->available_balance);

        // E il carrello è ancora lì, intatto: sarebbe il difetto peggiore
        // possibile aver pagato anche le tre cose che stava ancora valutando.
        $cart = Cart::attivoPer($buyerAccount->fresh());
        $this->assertSame(Cart::STATUS_ACTIVE, $cart->status);
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(3, (int) $cart->items()->sole()->quantity);
    }

    // =========================================================================
    // 4. La cassa immediata ha le stesse cose di quella del carrello
    // =========================================================================

    public function test_la_nota_al_venditore_arriva_sull_ordine(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), [
            'accetto_condizioni' => '1',
            'buyer_note'         => 'Citofonare Bianchi, secondo piano.',
        ]);

        $this->assertSame('Citofonare Bianchi, secondo piano.', Order::sole()->buyer_note);
    }

    public function test_si_puo_scegliere_un_indirizzo_dalla_rubrica(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $altro = \App\Models\ShippingAddress::create([
            'account_id'     => $buyerAccount->id,
            'label'          => 'Ufficio',
            'recipient_name' => 'Mario Rossi',
            'address'        => 'Via Torino 44',
            'city'           => 'Torino',
            'postal_code'    => '10100',
            'province'       => 'TO',
            'is_default'     => false,
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), [
            'accetto_condizioni' => '1',
            'indirizzo_scelto'   => (string) $altro->id,
        ]);

        $ordine = Order::sole();
        $this->assertSame('Via Torino 44', $ordine->shipping_address);
        $this->assertSame('Torino', $ordine->shipping_city);
    }

    public function test_un_indirizzo_di_un_altra_rubrica_non_diventa_la_destinazione(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$altro, $altroAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $suo = \App\Models\ShippingAddress::query()->where('account_id', $altroAccount->id)->sole();

        // Il messaggio ESATTO della guardia del controller, non uno qualsiasi:
        // dietro c'e' anche la difesa in profondita' di OrderService, che
        // rifiuterebbe comunque l'ordine con parole diverse. Senza questa
        // precisione il test resterebbe verde anche togliendo il controllo qui,
        // ed e' proprio quel controllo che sto difendendo.
        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), [
                'accetto_condizioni' => '1',
                'indirizzo_scelto'   => (string) $suo->id,
            ])
            ->assertSessionHas('portal_error', 'L\'indirizzo scelto non è nella tua rubrica.');

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    // =========================================================================
    // 5. Le guardie della cassa immediata
    // =========================================================================

    public function test_un_prodotto_sospeso_riporta_al_catalogo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['status' => 'suspended']);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', $listing))
            ->assertRedirect(route('portal.shop'));

        $this->assertSame(0, Order::count());
    }

    public function test_non_si_apre_la_cassa_per_un_prodotto_della_propria_azienda(): void
    {
        // Saldo capiente di proposito: con il conto a zero questo test
        // passerebbe per "saldo insufficiente" invece che per la guardia
        // sull'auto-acquisto, e resterebbe verde anche togliendola.
        [$company, $sellerUser] = $this->makeSeller(saldo: 100000);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($sellerUser)
            ->get(route('portal.shop.buy.form', $listing))
            ->assertRedirect(route('portal.shop.show', $listing))
            ->assertSessionHas('portal_error', 'Non puoi acquistare un prodotto pubblicato dalla tua stessa azienda.');

        $this->assertSame(0, Order::count());
    }

    public function test_con_saldo_insufficiente_la_cassa_non_si_apre(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 50000, kyPercentage: 100);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', $listing))
            ->assertRedirect(route('portal.shop.show', $listing))
            ->assertSessionHas('portal_error');
    }

    public function test_un_prodotto_variabile_senza_combinazione_non_apre_la_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $this->makeVariante($listing, [$taglie['M']], scorte: 5);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', $listing))
            ->assertRedirect(route('portal.shop.show', $listing));
    }

    public function test_una_combinazione_di_un_altro_prodotto_non_apre_la_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $mio    = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $altrui = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $varianteAltrui = $this->makeVariante($altrui, [$taglie['M']], scorte: 5);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', ['listing' => $mio->id, 'variant_id' => $varianteAltrui->id]))
            ->assertRedirect(route('portal.shop.show', $mio));

        $this->assertSame(0, Order::count());
    }

    public function test_la_cassa_immediata_mostra_la_combinazione_scelta(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $media = $this->makeVariante($listing, [$taglie['M']], deltaKy: 500, scorte: 5);

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', ['listing' => $listing->id, 'variant_id' => $media->id]))
            ->assertOk()
            ->assertSee('M')
            // Il totale è quello della combinazione (2000 + 500), non del prodotto base.
            ->assertSee(ky_format(2500));
    }
}
