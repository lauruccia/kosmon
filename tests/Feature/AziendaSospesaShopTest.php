<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Transfer;
use App\Services\CartService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * Sospendere un'azienda CONGELA IL COMMERCIO, non l'accesso.
 *
 * Decisione di Laura del 26/08/2026, dopo aver scoperto che `suspended_at`
 * non faceva quasi niente: il middleware `not.suspended` era montato solo sul
 * gruppo OAuth, il login non guardava la sospensione e nel motore dei
 * pagamenti la parola non compariva mai. Un'azienda sospesa continuava a
 * comprare, vendere e incassare.
 *
 * Adesso, e questi test lo difendono:
 *   - i suoi prodotti escono dal catalogo, dalle offerte e dalle fasce;
 *   - nessuno compra da lei, e lei non compra da nessuno;
 *   - **ma resta dentro**: vede i suoi prodotti, i suoi conti, e le quote in
 *     euro già aperte si possono ancora saldare. La sospensione ferma il
 *     traffico nuovo, non travolge chi ha già pagato in buona fede.
 *
 * Importi in CENTESIMI.
 */
class AziendaSospesaShopTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // Il catalogo
    // =========================================================================

    public function test_i_prodotti_di_un_azienda_sospesa_escono_dal_catalogo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$sana]     = $this->makeSeller();
        [$sospesa]  = $this->makeSeller();

        $buono = $this->makeListing($sana, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Lampada di sale']);
        $fuori = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tappeto persiano']);

        $sospesa->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($buyer)->get(route('portal.shop'))
            ->assertOk()
            ->assertSee('Lampada di sale')
            ->assertDontSee('Tappeto persiano');
    }

    public function test_il_prodotto_sospeso_esce_anche_dalle_offerte(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tappeto persiano']);

        \App\Models\ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 2000,
            'offer_price_ky'         => 1000,
            'offer_ky_percentage'    => 100,
            'expires_at'             => now()->addDays(3),
        ]);

        $this->actingAs($buyer)->get(route('portal.shop.offers'))
            ->assertOk()
            ->assertSee('Tappeto persiano');

        $sospesa->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($buyer)->get(route('portal.shop.offers'))
            ->assertOk()
            ->assertDontSee('Tappeto persiano');
    }

    public function test_l_azienda_sospesa_vede_ancora_i_propri_prodotti(): void
    {
        [$sospesa, $sellerUser] = $this->makeSeller();
        $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tappeto persiano']);
        $sospesa->forceFill(['suspended_at' => now()])->save();

        // Congelare il commercio non vuol dire renderla cieca sul proprio negozio.
        $this->actingAs($sellerUser)->get(route('portal.shop.mine'))
            ->assertOk()
            ->assertSee('Tappeto persiano');
    }

    // =========================================================================
    // Nessuno compra da lei
    // =========================================================================

    public function test_non_si_compra_da_un_venditore_sospeso(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);
        $sospesa->forceFill(['suspended_at' => now()])->save();

        try {
            app(OrderService::class)->place(
                buyerAccount: $buyerAccount,
                user: $buyer,
                righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
            );
            $this->fail('Da un venditore sospeso non si deve poter comprare.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non è al momento operativo', $e->getMessage());
        }

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    public function test_un_venditore_sospeso_non_finisce_nel_carrello(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);
        $sospesa->forceFill(['suspended_at' => now()])->save();

        // Si dice subito, invece di far riempire un carrello che alla cassa
        // non passerebbe comunque.
        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing), ['quantity' => 1])
            ->assertSessionHas('portal_error');

        $this->assertTrue(Cart::attivoPer($buyerAccount)->isVuoto());
    }

    public function test_la_pagina_prodotto_di_un_venditore_sospeso_non_ha_bottoni_d_acquisto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);
        $sospesa->forceFill(['suspended_at' => now()])->save();

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('non è al momento operativo', $html);
        $this->assertStringNotContainsString(route('portal.cart.add', $listing), $html);
        $this->assertStringNotContainsString(route('portal.shop.buy.form', $listing), $html);
    }

    /**
     * Le taglie si vedono sempre, anche dove non si puo' comprare (regola di
     * Laura del 26/08). Ma se il form d'acquisto non c'e', i radio non devono
     * agganciarcisi: resterebbero appesi a un `form="form-acquisto"` che nella
     * pagina non esiste.
     */
    public function test_le_taglie_restano_visibili_ma_non_puntano_a_un_form_inesistente(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $this->makeVariante($listing, [$taglie['M']], scorte: 5);

        $sospesa->forceFill(['suspended_at' => now()])->save();

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('variant-picker', $html, 'Che cosa c\'è in vendita si legge sempre.');
        $this->assertStringContainsString('Scegli taglia', $html);
        $this->assertStringNotContainsString('form="form-acquisto"', $html);
    }

    public function test_la_pagina_prodotto_lo_dice_anche_al_compratore_sospeso(): void
    {
        [$compratrice, $compratriceUser] = $this->makeSeller(saldo: 100000);
        [$sana] = $this->makeSeller();
        $listing = $this->makeListing($sana, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $this->makeVariante($listing, [$taglie['M']], scorte: 5);

        $compratrice->forceFill(['suspended_at' => now()])->save();

        $html = $this->actingAs($compratriceUser)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('La tua azienda è sospesa', $html);
        $this->assertStringNotContainsString('form="form-acquisto"', $html);
        $this->assertStringNotContainsString(route('portal.cart.add', $listing), $html);
    }

    public function test_la_cassa_immediata_non_si_apre_su_un_venditore_sospeso(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);
        $sospesa->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($buyer)
            ->get(route('portal.shop.buy.form', $listing))
            ->assertRedirect(route('portal.shop'));
    }

    public function test_un_carrello_riempito_prima_non_passa_in_cassa(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);

        // Prima la sospensione: il carrello si riempie regolarmente.
        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);
        $this->assertSame(1, Cart::attivoPer($buyerAccount)->items()->count());

        $sospesa->forceFill(['suspended_at' => now()])->save();

        try {
            app(CartService::class)->checkout($buyerAccount->fresh(), $buyer, '127.0.0.1');
            $this->fail('La cassa non doveva passare.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non è al momento operativo', $e->getMessage());
        }

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    // =========================================================================
    // Lei non compra da nessuno
    // =========================================================================

    public function test_un_azienda_sospesa_non_puo_comprare(): void
    {
        [$compratrice, $compratriceUser, $compratriceAccount] = $this->makeSeller(saldo: 100000);
        [$sana] = $this->makeSeller();
        $listing = $this->makeListing($sana, prezzo: 2000, kyPercentage: 100);

        $compratrice->forceFill(['suspended_at' => now()])->save();

        try {
            app(OrderService::class)->place(
                buyerAccount: $compratriceAccount->fresh(),
                user: $compratriceUser,
                righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
            );
            $this->fail('Un\'azienda sospesa non deve poter comprare.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('La tua azienda è sospesa', $e->getMessage());
        }

        $this->assertSame(100000, (int) $compratriceAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    public function test_un_azienda_sospesa_non_riempie_nemmeno_il_carrello(): void
    {
        [$compratrice, $compratriceUser, $compratriceAccount] = $this->makeSeller(saldo: 100000);
        [$sana] = $this->makeSeller();
        $listing = $this->makeListing($sana, prezzo: 2000, kyPercentage: 100);

        $compratrice->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($compratriceUser)
            ->post(route('portal.cart.add', $listing), ['quantity' => 1])
            ->assertSessionHas('portal_error');

        $this->assertTrue(Cart::attivoPer($compratriceAccount->fresh())->isVuoto());
    }

    public function test_un_privato_non_e_toccato_dalla_sospensione_di_nessuno(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sana] = $this->makeSeller();
        $listing = $this->makeListing($sana, prezzo: 2000, kyPercentage: 100);

        // Un compratore privato non ha un'azienda: la guardia non deve
        // inciampare sul null e bloccarlo.
        $ordine = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );

        $this->assertSame(2000, (int) $ordine->total_ky);
    }

    // =========================================================================
    // Ma gli ordini già aperti restano vivi
    // =========================================================================

    public function test_la_quota_in_euro_di_un_ordine_gia_aperto_si_salda_lo_stesso(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa, $sellerUser] = $this->makeSeller();
        $this->makeGateway($sospesa);
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 75);

        // L'ordine nasce prima della sospensione, con 5,00 EUR ancora da saldare.
        $ordine = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );
        $payment = $ordine->fresh()->payment;
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->fresh()->status);

        $sospesa->forceFill(['suspended_at' => now()])->save();
        $payment->update(['provider' => PaymentGateway::PROVIDER_BANK_TRANSFER]);

        // Questo è il punto della decisione di Laura: chi ha già pagato in
        // buona fede non deve restare con l'ordine bloccato a metà.
        $this->actingAs($sellerUser)
            ->post(route('portal.shop.orders.confirm-bank', $payment))
            ->assertSessionHas('portal_success');

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    public function test_l_ordine_gia_registrato_non_sparisce(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100);

        $ordine = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );

        $sospesa->forceFill(['suspended_at' => now()])->save();

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
        $this->assertSame(1, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
    }

    // =========================================================================
    // Revocare la sospensione rimette tutto com'era
    // =========================================================================

    public function test_togliere_la_sospensione_rimette_i_prodotti_in_vendita(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$sospesa] = $this->makeSeller();
        $listing = $this->makeListing($sospesa, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Tappeto persiano']);

        $sospesa->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($buyer)->get(route('portal.shop'))->assertDontSee('Tappeto persiano');

        // Lo `status` del prodotto non è mai stato toccato: revocare la
        // sospensione lo rimette su com'era, senza riattivarlo a mano.
        $sospesa->forceFill(['suspended_at' => null])->save();

        $this->actingAs($buyer)->get(route('portal.shop'))->assertSee('Tappeto persiano');
        $this->assertSame('active', $listing->fresh()->status);

        $ordine = app(OrderService::class)->place(
            buyerAccount: $buyerAccount->fresh(),
            user: $buyer,
            righe: [['listing' => $listing->fresh(), 'variant' => null, 'quantity' => 1]],
        );
        $this->assertSame(2000, (int) $ordine->total_ky);
    }
}
