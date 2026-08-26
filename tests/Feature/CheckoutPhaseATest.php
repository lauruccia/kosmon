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
 * FASE A — la cassa (PIANO_ESPERIENZA_ACQUISTO.md, 26/08/2026).
 *
 * Prima di questa fase fra il carrello e l'addebito c'era soltanto un
 * confirm() del browser. Questi test difendono tre cose, in ordine di
 * importanza:
 *
 *   1. **Aprire la cassa non costa niente.** Un GET non deve muovere un
 *      centesimo, per nessun motivo.
 *   2. **Senza la spunta non si paga.** E' l'unico gesto esplicito rimasto ora
 *      che la finestrella del browser non c'e' piu': se salta quella, si e'
 *      tornati a un clic solo fra il carrello e i soldi.
 *   3. **Un indirizzo parziale non cancella quello buono.** Correggere
 *      l'indirizzo in cassa e' comodo, ma non deve poter rompere un indirizzo
 *      che funzionava.
 *
 * Importi in CENTESIMI.
 */
class CheckoutPhaseATest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** Il payload minimo che la cassa accetta. */
    private function payload(array $extra = []): array
    {
        return array_merge(['accetto_condizioni' => '1'], $extra);
    }

    // =========================================================================
    // 1. La pagina di cassa
    // =========================================================================

    public function test_la_cassa_mostra_i_venditori_il_totale_e_la_spunta_delle_condizioni(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2500, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);

        $this->actingAs($buyer)
            ->get(route('portal.cart.checkout.form'))
            ->assertOk()
            ->assertSee($listing->title)
            ->assertSee($company->name)
            ->assertSee('50,00 KY')          // 2 x 25,00
            ->assertSee('accetto_condizioni', false)
            ->assertSee('obbligo di pagamento');
    }

    public function test_aprire_la_cassa_non_addebita_niente(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $contoVenditore] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))->assertOk();
        $this->actingAs($buyer)->get(route('portal.cart.checkout.form'))->assertOk();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $contoVenditore->fresh()->available_balance);
        $this->assertSame(1, Cart::attivoPer($buyerAccount->fresh())->items()->count());
    }

    // =========================================================================
    // 2. Le guardie: alla cassa non ci si arriva di striscio
    // =========================================================================

    public function test_la_cassa_di_un_carrello_vuoto_rimanda_al_carrello(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)
            ->get(route('portal.cart.checkout.form'))
            ->assertRedirect(route('portal.cart'))
            ->assertSessionHas('portal_error');
    }

    public function test_un_prodotto_indisponibile_non_fa_aprire_la_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $listing->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($buyer)
            ->get(route('portal.cart.checkout.form'))
            ->assertRedirect(route('portal.cart'))
            ->assertSessionHas('portal_error');
    }

    public function test_il_saldo_insufficiente_non_fa_aprire_la_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 1000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->get(route('portal.cart.checkout.form'))
            ->assertRedirect(route('portal.cart'))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'Saldo insufficiente'));
    }

    public function test_senza_indirizzo_un_prodotto_da_spedire_non_fa_aprire_la_cassa(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000, conIndirizzo: false);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->get(route('portal.cart.checkout.form'))
            ->assertRedirect(route('portal.cart'))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'indirizzo'));
    }

    // =========================================================================
    // 3. La spunta sulle condizioni
    // =========================================================================

    public function test_senza_la_spunta_sulle_condizioni_non_si_paga(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $contoVenditore] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), [])   // niente spunta
            ->assertSessionHasErrors('accetto_condizioni');

        $this->assertSame(0, Order::count(), 'Senza consenso esplicito non deve nascere nessun ordine.');
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $contoVenditore->fresh()->available_balance);
        $this->assertSame(1, Cart::attivoPer($buyerAccount->fresh())->items()->count(), 'Il carrello resta pieno.');
    }

    public function test_una_spunta_a_zero_vale_come_nessuna_spunta(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), ['accetto_condizioni' => '0'])
            ->assertSessionHasErrors('accetto_condizioni');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_con_la_spunta_si_paga_e_si_finisce_sulla_pagina_grazie(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $contoVenditore] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $risposta = $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), $this->payload())
            ->assertSessionHas('portal_success');

        $ordine = Order::sole();
        $risposta->assertRedirect(route('portal.cart.thanks', ['ids' => $ordine->uuid]));

        $this->assertSame(98000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(2000, $contoVenditore->fresh()->available_balance);
    }

    // =========================================================================
    // 4. La nota per il venditore
    // =========================================================================

    public function test_la_nota_del_compratore_finisce_sullordine(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload([
            'buyer_note' => '  Citofonare Rossi, consegnare dopo le 15.  ',
        ]));

        $this->assertSame('Citofonare Rossi, consegnare dopo le 15.', Order::sole()->buyer_note);
    }

    public function test_senza_nota_lordine_resta_senza_nota(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload(['buyer_note' => '   ']));

        $this->assertNull(Order::sole()->buyer_note);
    }

    public function test_una_nota_troppo_lunga_viene_rifiutata_senza_addebitare(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), $this->payload(['buyer_note' => str_repeat('a', 501)]))
            ->assertSessionHasErrors('buyer_note');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_con_piu_venditori_la_stessa_nota_arriva_a_tutti_gli_ordini(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller();
        [$secondaAzienda] = $this->makeSeller();

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100)));
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->makeListing($secondaAzienda, prezzo: 3000, kyPercentage: 100)));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload(['buyer_note' => 'Fragile']));

        $this->assertSame(2, Order::count());
        $this->assertSame(['Fragile', 'Fragile'], Order::query()->orderBy('id')->pluck('buyer_note')->all());
    }

    // =========================================================================
    // 5. L'indirizzo corretto in cassa
    // =========================================================================

    public function test_lindirizzo_corretto_in_cassa_si_salva_sul_conto_e_finisce_sullordine(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload([
            'shipping_recipient_name' => 'Luisa Bianchi',
            'shipping_address'        => 'Corso Italia 42',
            'shipping_city'           => 'Torino',
            'shipping_postal_code'    => '10121',
            'shipping_province'       => 'TO',
            'shipping_phone'          => '3339998877',
        ]));

        $conto = $buyerAccount->fresh();
        $this->assertSame('Luisa Bianchi', $conto->shipping_recipient_name);
        $this->assertSame('Corso Italia 42', $conto->shipping_address);
        $this->assertSame('Torino', $conto->shipping_city);

        $ordine = Order::sole();
        $this->assertSame('Luisa Bianchi', $ordine->shipping_recipient_name, 'L\'ordine deve partire verso l\'indirizzo appena corretto, non verso quello vecchio.');
        $this->assertSame('Corso Italia 42', $ordine->shipping_address);
        $this->assertSame('10121', $ordine->shipping_postal_code);
    }

    public function test_un_indirizzo_parziale_non_cancella_quello_buono_e_non_addebita(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), $this->payload([
                'shipping_city' => 'Napoli',   // solo la citta': indirizzo monco
            ]))
            ->assertSessionHas('portal_error');

        $conto = $buyerAccount->fresh();
        $this->assertSame('Via Roma 1', $conto->shipping_address, 'L\'indirizzo che funzionava non deve essere stato toccato.');
        $this->assertSame('Milano', $conto->shipping_city);
        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $conto->available_balance);
    }

    public function test_non_inviare_nessun_campo_indirizzo_lascia_quello_del_profilo(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 0,
        ]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());

        $this->assertSame('Via Roma 1', $buyerAccount->fresh()->shipping_address);
        $this->assertSame('Via Roma 1', Order::sole()->shipping_address);
    }

    // =========================================================================
    // 6. La pagina "grazie"
    // =========================================================================

    public function test_la_pagina_grazie_mostra_il_numero_dordine(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());

        $ordine = Order::sole();

        $this->actingAs($buyer)
            ->get(route('portal.cart.thanks', ['ids' => $ordine->uuid]))
            ->assertOk()
            ->assertSee(strtoupper(substr($ordine->uuid, 0, 8)))
            ->assertSee($company->name)
            ->assertSee($listing->title);
    }

    public function test_la_pagina_grazie_non_mostra_gli_ordini_di_un_altro(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$estraneo] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());
        $ordineAltrui = Order::sole();

        $this->actingAs($estraneo)
            ->get(route('portal.cart.thanks', ['ids' => $ordineAltrui->uuid]))
            ->assertRedirect(route('portal.shop'));
    }

    public function test_la_pagina_grazie_senza_ids_rimanda_allo_shop(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)
            ->get(route('portal.cart.thanks'))
            ->assertRedirect(route('portal.shop'));
    }

    public function test_con_quota_in_euro_la_pagina_grazie_offre_il_link_per_saldarla(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        // 50% KY: meta' in KY, meta' in euro. Senza un gateway configurato il
        // venditore non puo' incassare euro e l'ordine non nascerebbe.
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 4000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());

        $ordine = Order::sole();
        $this->assertNotNull($ordine->payment, 'Con una quota in euro deve esistere il pagamento da saldare.');

        $this->actingAs($buyer)
            ->get(route('portal.cart.thanks', ['ids' => $ordine->uuid]))
            ->assertOk()
            ->assertSee(route('portal.shop.orders.pay', $ordine->payment), false);
    }

    public function test_la_pagina_grazie_elenca_tutti_gli_ordini_di_un_carrello_multi_venditore(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller();
        [$secondaAzienda] = $this->makeSeller();

        $this->actingAs($buyer)->post(route('portal.cart.add', $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100)));
        $this->actingAs($buyer)->post(route('portal.cart.add', $this->makeListing($secondaAzienda, prezzo: 3000, kyPercentage: 100)));
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());

        $ids = Order::query()->orderBy('id')->pluck('uuid')->implode(',');

        $this->actingAs($buyer)
            ->get(route('portal.cart.thanks', ['ids' => $ids]))
            ->assertOk()
            ->assertSee($primaAzienda->name)
            ->assertSee($secondaAzienda->name);
    }

    // =========================================================================
    // 7. Il doppio invio
    // =========================================================================

    public function test_il_secondo_invio_della_cassa_non_riaddebita(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $contoVenditore] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)->post(route('portal.cart.checkout'), $this->payload());
        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'), $this->payload())
            ->assertSessionHas('portal_error');

        $this->assertSame(1, Order::count(), 'Il secondo invio non deve creare un secondo ordine.');
        $this->assertSame(98000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(2000, $contoVenditore->fresh()->available_balance);
    }

    // =========================================================================
    // 8. Il carrello non paga piu' da solo
    // =========================================================================

    public function test_il_carrello_porta_alla_cassa_e_non_addebita_piu_da_se(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing));

        $this->actingAs($buyer)
            ->get(route('portal.cart'))
            ->assertOk()
            ->assertSee(route('portal.cart.checkout.form'), false)
            ->assertDontSee('return confirm(\'Confermi l\\\'ordine?', false);
    }
}
