<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingVariant;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE D — prodotti variabili (PIANO_CARRELLO_VARIANTI.md).
 *
 * Due idee reggono tutta la fase, e questi test le difendono:
 *
 * 1. **Il prezzo della variante è un DELTA**, non un prezzo assoluto. È quello
 *    che permette alle Offerte della settimana di continuare a funzionare senza
 *    un secondo motore di prezzi: l'offerta abbassa la base, la XL resta "due
 *    euro più della base".
 *
 * 2. **Le scorte stanno sulla combinazione.** Un prodotto può essere pieno di
 *    magliette e non avere più la M — e chi compra la M deve sentirselo dire.
 *
 * Importi in CENTESIMI.
 */
class VariantsPhaseDTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. Il vocabolario dell'admin
    // =========================================================================

    public function test_gli_attributi_hanno_uno_slug_stabile_che_sopravvive_al_rinominare(): void
    {
        $taglie = $this->makeAttributo('Taglia', ['S', 'M', 'L']);
        $attributo = $taglie['M']->attribute;

        $this->assertSame('taglia', $attributo->slug);
        $this->assertSame('m', $taglie['M']->slug);

        // L'admin cambia idea sull'etichetta: lo slug non si muove.
        $attributo->update(['name' => 'Misura']);
        $taglie['M']->update(['value' => 'Media']);

        $this->assertSame('taglia', $attributo->fresh()->slug);
        $this->assertSame('m', $taglie['M']->fresh()->slug);
    }

    public function test_due_attributi_con_lo_stesso_nome_non_si_pestano_i_piedi(): void
    {
        $this->makeAttributo('Colore', ['rosso']);
        $secondo = $this->makeAttributo('Colore', ['blu']);

        $this->assertSame('colore-2', $secondo['blu']->attribute->slug);
    }

    // =========================================================================
    // 2. Il prezzo: base + delta
    // =========================================================================

    public function test_la_variante_costa_il_prezzo_base_piu_il_suo_delta(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'XL']);
        $media  = $this->makeVariante($listing, [$taglie['M']], deltaKy: 0);
        $grande = $this->makeVariante($listing, [$taglie['XL']], deltaKy: 500);

        $this->assertSame(2000, $media->prezzoEffettivo());
        $this->assertSame(2500, $grande->prezzoEffettivo());

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), [
            'variant_id' => $grande->id,
            'quantity'   => 2,
        ]);

        $order = Order::query()->sole();
        $this->assertSame(5000, $order->total_ky, 'Due XL a 25,00 l\'una.');
        $this->assertSame(95000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(5000, $sellerAccount->fresh()->available_balance);
    }

    public function test_il_delta_puo_essere_negativo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie  = $this->makeAttributo('Taglia', ['S']);
        $piccola = $this->makeVariante($listing, [$taglie['S']], deltaKy: -300);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $piccola->id]);

        $this->assertSame(1700, Order::query()->sole()->total_ky);
    }

    public function test_con_un_offerta_attiva_il_delta_si_somma_al_prezzo_scontato(): void
    {
        // È IL test che giustifica tutta la scelta del delta. Con i prezzi
        // assoluti, un'offerta sul prodotto non avrebbe potuto toccare le
        // varianti — e avremmo dovuto vietare le offerte sui prodotti
        // variabili, oppure scrivere un secondo motore di prezzi.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['XL']);
        $grande = $this->makeVariante($listing, [$taglie['XL']], deltaKy: 500);

        ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 2000,
            'offer_price_ky'         => 1200,
            'offer_ky_percentage'    => 100,
            'expires_at'             => now()->addDays(3),
        ]);

        // 12,00 di offerta + 5,00 di delta = 17,00 (non 25,00, non 12,00).
        $this->assertSame(1700, $grande->fresh(['listing.activeOffer'])->prezzoEffettivo());

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $grande->id]);

        $this->assertSame(1700, Order::query()->sole()->total_ky);
    }

    public function test_il_mix_ky_eur_resta_quello_del_prodotto_non_della_variante(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 50);

        $taglie = $this->makeAttributo('Taglia', ['XL']);
        $grande = $this->makeVariante($listing, [$taglie['XL']], deltaKy: 1000);

        // 30,00 pieni, divisi a metà come vuole il prodotto padre.
        $this->assertSame(3000, $grande->prezzoEffettivo());
        $this->assertSame(1500, $grande->quotaKy());
        $this->assertSame(1500, $grande->quotaEuro());

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $grande->id]);

        $order = Order::query()->sole();
        $this->assertSame(1500, $order->total_ky);
        $this->assertSame(1500, $order->total_eur);
        $this->assertSame(50, $order->items()->sole()->ky_percentage);
    }

    // =========================================================================
    // 3. Le scorte stanno sulla combinazione
    // =========================================================================

    public function test_l_acquisto_scala_le_scorte_della_combinazione_non_del_prodotto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100, extra: ['stock_quantity' => 50]);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $media  = $this->makeVariante($listing, [$taglie['M']], scorte: 3);
        $lunga  = $this->makeVariante($listing, [$taglie['L']], scorte: 7);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), [
            'variant_id' => $media->id,
            'quantity'   => 2,
        ]);

        $this->assertSame(1, $media->fresh()->stock_quantity, 'Scalata la M...');
        $this->assertSame(7, $lunga->fresh()->stock_quantity, '...e solo la M.');
        $this->assertSame(50, $listing->fresh()->stock_quantity, 'Le scorte del prodotto non si toccano.');
    }

    public function test_una_combinazione_esaurita_non_si_compra_anche_se_le_altre_ci_sono(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $media  = $this->makeVariante($listing, [$taglie['M']], scorte: 0);
        $this->makeVariante($listing, [$taglie['L']], scorte: 10);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['variant_id' => $media->id])
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'esaurita'));

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_una_combinazione_spenta_non_si_compra(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $media  = $this->makeVariante($listing, [$taglie['M']]);
        $media->update(['is_active' => false]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['variant_id' => $media->id])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
    }

    // =========================================================================
    // 4. Le regole di scelta
    // =========================================================================

    public function test_un_prodotto_variabile_non_si_compra_senza_scegliere(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $this->makeVariante($listing, [$taglie['M']]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'Scegli una variante'));

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    public function test_non_si_puo_usare_la_variante_di_un_altro_prodotto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $mio   = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $altro = $this->makeListing($company, prezzo: 9000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $varianteAltrui = $this->makeVariante($altro, [$taglie['M']]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $mio), ['variant_id' => $varianteAltrui->id])
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
    }

    public function test_un_prodotto_semplice_ignora_una_variante_passata_per_sbaglio(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $semplice = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $semplice), ['quantity' => 1]);

        $this->assertSame(1000, Order::query()->sole()->total_ky);
        $this->assertNull(OrderItem::query()->sole()->listing_variant_id);
    }

    // =========================================================================
    // 5. Lo snapshot sull'ordine
    // =========================================================================

    public function test_l_ordine_congela_l_etichetta_della_combinazione(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $colori = $this->makeAttributo('Colore', ['rosso']);
        $variante = $this->makeVariante($listing, [$taglie['M'], $colori['rosso']], deltaKy: 200);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $variante->id]);

        $riga = OrderItem::query()->sole();
        $this->assertSame('Taglia: M · Colore: rosso', $riga->variant_label);
        $this->assertSame(1200, $riga->unit_price_ky);

        // L'admin rinomina il valore e il venditore cancella la combinazione:
        // la riga dell'ordine resta esattamente com'era.
        $colori['rosso']->update(['value' => 'Bordeaux']);
        $variante->delete();

        $riga->refresh();
        $this->assertSame('Taglia: M · Colore: rosso', $riga->variant_label);
        $this->assertNull($riga->listing_variant_id);
        $this->assertStringContainsString('Taglia: M', $riga->titolo_completo);
    }

    // =========================================================================
    // 6. Il carrello
    // =========================================================================

    public function test_due_combinazioni_dello_stesso_prodotto_sono_due_righe_di_carrello(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $media = $this->makeVariante($listing, [$taglie['M']]);
        $lunga = $this->makeVariante($listing, [$taglie['L']], deltaKy: 300);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $media->id]);
        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $lunga->id, 'quantity' => 2]);

        $cart = Cart::attivoPer($buyerAccount->fresh());
        $this->assertSame(2, $cart->items()->count(), 'La M e la L sono due righe.');
        $this->assertSame(3, $cart->totalePezzi());

        // 10,00 + (13,00 x 2) = 36,00
        $cart->load('items.listing.activeOffer', 'items.variant.values.attribute', 'items.listing.company');
        $this->assertSame(3600, $cart->totaleKy());
    }

    public function test_la_stessa_combinazione_due_volte_somma_le_quantita(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $media = $this->makeVariante($listing, [$taglie['M']]);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $media->id]);
        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $media->id, 'quantity' => 2]);

        $cart = Cart::attivoPer($buyerAccount->fresh());
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(3, (int) $cart->items()->sole()->quantity);
    }

    public function test_al_carrello_non_si_aggiunge_un_variabile_senza_sceglierne_una(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $this->makeVariante($listing, [$taglie['M']]);

        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing))
            ->assertSessionHas('portal_error', fn ($e) => str_contains((string) $e, 'Scegli una variante'));

        $this->assertSame(0, CartItem::count());
    }

    public function test_la_cassa_porta_le_combinazioni_fino_all_ordine(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $media = $this->makeVariante($listing, [$taglie['M']], scorte: 5);
        $lunga = $this->makeVariante($listing, [$taglie['L']], deltaKy: 300, scorte: 5);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $media->id, 'quantity' => 2]);
        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $lunga->id]);

        $this->actingAs($buyer)->post(route('portal.cart.checkout'))->assertSessionHas('portal_success');

        // Un ordine solo (stesso venditore) con DUE righe, una per combinazione.
        $order = Order::query()->sole();
        $this->assertCount(2, $order->items()->get());
        $this->assertSame(3300, $order->total_ky, '(10,00 x 2) + 13,00');
        $this->assertSame(3300, $sellerAccount->fresh()->available_balance);

        $etichette = $order->items()->pluck('variant_label')->all();
        $this->assertContains('Taglia: M', $etichette);
        $this->assertContains('Taglia: L', $etichette);

        $this->assertSame(3, $media->fresh()->stock_quantity);
        $this->assertSame(4, $lunga->fresh()->stock_quantity);
    }

    public function test_se_una_combinazione_si_esaurisce_mentre_e_nel_carrello_la_cassa_si_ferma(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $media = $this->makeVariante($listing, [$taglie['M']], scorte: 5);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['variant_id' => $media->id, 'quantity' => 3]);

        // Qualcun altro le compra tutte mentre il carrello aspetta.
        $media->update(['stock_quantity' => 1]);

        $this->actingAs($buyer)
            ->post(route('portal.cart.checkout'))
            ->assertSessionHas('portal_error');

        $this->assertSame(0, Order::count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
    }

    // =========================================================================
    // 6b. Il servizio si difende da solo
    //
    //     I controlli sulle combinazioni esistono in DUE punti: nel controller,
    //     che dà il messaggio giusto prima ancora di aprire una transazione, e
    //     dentro OrderService, che è l'ultima porta prima del denaro. Due
    //     mutazioni deliberate hanno mostrato che togliendo il controllo di
    //     dentro i test restavano verdi — perché passavano tutti dal
    //     controller. Questi due chiamano il servizio in faccia.
    // =========================================================================

    public function test_il_servizio_rifiuta_un_variabile_senza_combinazione(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $this->makeVariante($listing, [$taglie['M']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scegli una variante');

        app(\App\Services\OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing->fresh(), 'quantity' => 1]],
        );
    }

    public function test_il_servizio_rifiuta_una_combinazione_di_un_altro_prodotto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $mio   = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);
        $altro = $this->makeListing($company, prezzo: 9000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $variante = $this->makeVariante($altro, [$taglie['M']]);
        $this->makeVariante($mio, [$taglie['M']]);

        $this->expectException(\RuntimeException::class);

        app(\App\Services\OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $mio->fresh(), 'variant' => $variante, 'quantity' => 1]],
        );
    }

    // =========================================================================
    // 7. Le pagine
    // =========================================================================

    public function test_la_pagina_prodotto_elenca_le_combinazioni_col_loro_prezzo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'XL']);
        $this->makeVariante($listing, [$taglie['M']]);
        $this->makeVariante($listing, [$taglie['XL']], deltaKy: 500);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('name="variant_id"', $html);
        $this->assertStringContainsString('20,00 KY', $html);
        $this->assertStringContainsString('25,00 KY', $html, 'La XL costa il prezzo base più il suo delta.');
    }

    public function test_col_saldo_insufficiente_le_varianti_si_vedono_lo_stesso(): void
    {
        // Segnalato da Laura il 25/08/2026 su /shop/123: il selettore stava
        // SOLO dentro il form di acquisto, e quel form compare solo se il
        // saldo basta. Chi aveva 71 KY su un prodotto da 100 vedeva
        // "Ricarica il tuo conto" e un "Aggiungi al carrello" nudo — delle
        // taglie nemmeno l'ombra.
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 1000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'XL']);
        $media  = $this->makeVariante($listing, [$taglie['M']]);
        $this->makeVariante($listing, [$taglie['XL']], deltaKy: 1000);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('Saldo insufficiente', $html, 'Con 10,00 KY su un prodotto da 100,00 il saldo non basta davvero.');
        $this->assertStringContainsString('name="variant_id"', $html, 'E le taglie si devono vedere lo stesso.');
        $this->assertStringContainsString('110,00 KY', $html, 'Anche quella che costa di più.');

        // E il bottone del carrello dev'essere davvero utilizzabile: prima
        // partiva senza variante e sbatteva contro "Scegli una variante".
        $this->actingAs($buyer)
            ->post(route('portal.cart.add', $listing), ['variant_id' => $media->id, 'quantity' => 1])
            ->assertSessionMissing('portal_error');

        $this->assertSame(1, Cart::attivoPer($buyerAccount)->items()->count());
    }

    public function test_il_saldo_richiesto_si_misura_sulla_variante_piu_economica(): void
    {
        // Il prezzo base non è quello che si paga: se la S costa meno del
        // prodotto, chiedere il prezzo base vorrebbe dire negare l'acquisto a
        // chi la S se la può permettere.
        [$buyer] = $this->makeBuyer(saldo: 9000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 100);

        $taglie  = $this->makeAttributo('Taglia', ['S', 'XL']);
        $this->makeVariante($listing, [$taglie['S']], deltaKy: -2000);   // 80,00
        $this->makeVariante($listing, [$taglie['XL']], deltaKy: 2000);   // 120,00

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringNotContainsString('Saldo insufficiente', $html, '90,00 bastano per la S da 80,00.');
        $this->assertStringContainsString('Acquista la variante scelta', $html);
        // Il prezzo in cima dev'essere "da 80,00", non "100,00" secchi: su un
        // prodotto in cui la S costa 80 e la XL 120, il prezzo base non lo
        // paga nessuno.
        $this->assertMatchesRegularExpression('/>da<\/div>\s*<div[^>]*>\s*80,00\s*<\/div>/', $html);
    }

    public function test_una_combinazione_esaurita_si_vede_ma_non_si_sceglie(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $this->makeVariante($listing, [$taglie['M']], scorte: 0);
        $this->makeVariante($listing, [$taglie['L']], scorte: 5);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        // Chi cerca la M deve vedere che la M esiste ed è finita, non credere
        // che quel venditore non la faccia.
        $media = $listing->variants()->get()->first(fn ($v) => $v->etichetta_corta === 'M');
        $lunga = $listing->variants()->get()->first(fn ($v) => $v->etichetta_corta === 'L');

        $this->assertStringContainsString('esaurita', $html);
        $this->assertMatchesRegularExpression(
            '/value="' . $media->id . '"[^>]*\sdisabled/',
            $html,
            'La M si vede ma non si può scegliere.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="' . $lunga->id . '"[^>]*\sdisabled/',
            $html,
            'La L invece sì.'
        );
    }

    public function test_la_scelta_della_variante_sta_sopra_il_prezzo_e_resta_agganciata_al_form(): void
    {
        // Il riquadro sta FUORI dal form, sopra il prezzo (richiesta di Laura,
        // 25/08/2026): i radio ci arrivano con l'attributo `form` dell'HTML.
        // È l'aggancio fragile di tutta la sidebar — se qualcuno toglie l'id
        // al form, i radio smettono di essere inviati e l'acquisto fallisce
        // con "Scegli una variante" senza che si veda perché.
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M', 'L']);
        $media  = $this->makeVariante($listing, [$taglie['M']]);

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $listing))->assertOk()->getContent();

        $this->assertStringContainsString('id="form-acquisto"', $html, 'Il form deve avere l\'id a cui i radio si agganciano.');
        $this->assertMatchesRegularExpression(
            '/value="' . $media->id . '"[^>]*\sform="form-acquisto"/',
            $html,
            'Ogni radio deve dichiarare a quale form appartiene.'
        );

        $posSelettore = mb_strpos($html, 'variant-picker');
        $posPrezzo    = mb_strpos($html, 'KY (KMoney)');
        $this->assertNotFalse($posSelettore);
        $this->assertLessThan($posPrezzo, $posSelettore, 'Prima si sceglie la taglia, poi si legge il prezzo.');

        // E l'aggancio deve funzionare davvero: acquisto con la M.
        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $media->id])
            ->assertSessionMissing('portal_error');
    }

    public function test_le_varianti_sono_pulsanti_finche_non_diventano_troppe(): void
    {
        // Richiesta di Laura, 25/08/2026: la tendina nascondeva l'esistenza
        // stessa delle taglie dietro un clic. Con pochi valori si vedono tutte
        // insieme; oltre le 12 combinazioni i pulsanti sarebbero un muro e si
        // torna alla tendina.
        [$buyer] = $this->makeBuyer(saldo: 1000000);
        [$company] = $this->makeSeller(saldo: 0);

        $poche = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M', 'L']);
        foreach ($taglie as $valore) {
            $this->makeVariante($poche, [$valore]);
        }

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $poche))->assertOk()->getContent();

        $this->assertStringContainsString('class="variant-radio"', $html);
        $this->assertStringNotContainsString('<select name="variant_id"', $html);
        $this->assertStringContainsString('Scegli taglia', $html, 'Il titolo prende il nome dell\'attributo.');

        // Tredici combinazioni: si passa alla tendina.
        $tante = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $numeri = $this->makeAttributo('Formato', array_map('strval', range(1, 13)));
        foreach ($numeri as $valore) {
            $this->makeVariante($tante, [$valore]);
        }

        $html = $this->actingAs($buyer)->get(route('portal.shop.show', $tante))->assertOk()->getContent();

        $this->assertStringContainsString('<select name="variant_id"', $html);
        $this->assertStringNotContainsString('class="variant-radio"', $html);
    }

    public function test_dagli_elenchi_prodotti_si_arriva_alle_varianti(): void
    {
        // Le varianti si gestiscono su un prodotto che ESISTE gia', quindi non
        // stanno nel form di creazione: il collegamento deve esserci negli
        // elenchi, che è il posto dove uno va a cercarlo (segnalato da Laura il
        // 25/08, dopo il primo deploy: aveva creato gli attributi e non trovava
        // dove usarli).
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        // Il venditore, da "I miei prodotti".
        $this->actingAs($sellerUser)->get(route('portal.shop.mine'))
            ->assertOk()
            ->assertSee(route('portal.shop.variants', $listing));

        // L'admin, dall'elenco prodotti del backoffice.
        [$admin] = $this->makeBuyer(saldo: 1000);
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSee(route('portal.shop.variants', $listing));

        // E il form di creazione dice dove sono, invece di lasciare cercare.
        $this->actingAs($admin)->get(route('admin.listings.create'))
            ->assertOk()
            ->assertSee('Si aggiungono', false);

        // Anche il form di MODIFICA porta alle varianti: è il primo posto dove
        // uno va a cercarle.
        $this->actingAs($sellerUser)->get(route('portal.shop.edit', $listing))
            ->assertOk()
            ->assertSee(route('portal.shop.variants', $listing));
    }

    public function test_il_venditore_genera_le_combinazioni_dai_valori_spuntati(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $colori = $this->makeAttributo('Colore', ['rosso', 'blu']);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [
                $taglie['S']->id, $taglie['M']->id,
                $colori['rosso']->id, $colori['blu']->id,
            ],
        ])->assertSessionHas('portal_success');

        // Due taglie per due colori: quattro combinazioni.
        $this->assertSame(4, $listing->variants()->count());
        $this->assertTrue($listing->fresh()->has_variants);

        $etichette = ListingVariant::query()->get()->map(fn ($v) => $v->etichetta_corta)->sort()->values()->all();
        $this->assertSame(['M · blu', 'M · rosso', 'S · blu', 'S · rosso'], $etichette);
    }

    public function test_rigenerare_non_tocca_le_combinazioni_che_ci_sono_gia(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $esistente = $this->makeVariante($listing, [$taglie['S']], deltaKy: 700, scorte: 42);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ]);

        $this->assertSame(2, $listing->variants()->count(), 'Aggiunta solo la M.');

        $esistente->refresh();
        $this->assertSame(700, $esistente->price_delta_ky, 'Il prezzo della S non si tocca.');
        $this->assertSame(42, $esistente->stock_quantity, 'E nemmeno la sua giacenza.');
    }

    // ── Rigenerare dopo aver cambiato i valori ───────────────────────────────
    //
    // Domanda di Laura, 25/08/2026: "se clicco su genera combinazioni e poi
    // voglio aggiungere una o più varianti cosa succede?". I tre casi che
    // capitano davvero, messi per iscritto qui perché non si possano rompere
    // in silenzio.

    public function test_aggiungere_un_valore_allo_stesso_attributo_aggiunge_solo_quello_che_manca(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M', 'L']);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ]);

        // Il venditore mette prezzo e giacenza sulla S, poi ci ripensa e
        // aggiunge la L.
        $esse = $listing->variants()->get()->first(fn ($v) => $v->etichetta_corta === 'S');
        $esse->update(['price_delta_ky' => 300, 'stock_quantity' => 12]);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id, $taglie['L']->id],
        ])->assertSessionHas('portal_success');

        $attive = $listing->variants()->where('is_active', true)->get();
        $this->assertSame(
            ['L', 'M', 'S'],
            $attive->map(fn ($v) => $v->etichetta_corta)->sort()->values()->all(),
            'Le due di prima restano, si aggiunge solo la L.'
        );

        $esse->refresh();
        $this->assertSame(300, $esse->price_delta_ky, 'Il lavoro già fatto sulla S non si perde.');
        $this->assertSame(12, $esse->stock_quantity);
        $this->assertSame(0, $listing->variants()->where('is_active', false)->count(), 'Niente da spegnere.');
    }

    public function test_aggiungere_un_secondo_attributo_spegne_le_combinazioni_rimaste_a_meta(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $colori = $this->makeAttributo('Colore', ['rosso', 'blu']);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ]);
        $this->assertSame(2, $listing->variants()->count());

        // Ora aggiunge il colore. Il prodotto cartesiano fa quattro
        // combinazioni COMPLETE; le due sole-taglia non hanno più senso —
        // chi compra vedrebbe nel selettore sia "S" sia "S · rosso".
        $risposta = $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [
                $taglie['S']->id, $taglie['M']->id,
                $colori['rosso']->id, $colori['blu']->id,
            ],
        ]);

        $attive = $listing->variants()->where('is_active', true)->get();
        $this->assertSame(
            ['M · blu', 'M · rosso', 'S · blu', 'S · rosso'],
            $attive->map(fn ($v) => $v->etichetta_corta)->sort()->values()->all(),
            'Restano attive solo le quattro combinazioni complete.'
        );

        $spente = $listing->variants()->where('is_active', false)->get();
        $this->assertSame(
            ['M', 'S'],
            $spente->map(fn ($v) => $v->etichetta_corta)->sort()->values()->all(),
            'Le due a metà sono spente, NON cancellate: possono già essere state vendute.'
        );

        // E il venditore deve capire che cosa gli è successo, non trovarsele
        // spente per conto loro.
        $this->assertStringContainsString('disattivate', $risposta->getSession()->get('portal_success'));
    }

    public function test_togliere_un_valore_spegne_le_combinazioni_che_lo_usavano(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M', 'L']);

        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id, $taglie['L']->id],
        ]);

        // Della L non ne vuole più: rigenera senza spuntarla.
        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ]);

        $this->assertSame(
            ['M', 'S'],
            $listing->variants()->where('is_active', true)->get()
                ->map(fn ($v) => $v->etichetta_corta)->sort()->values()->all()
        );

        $elle = $listing->variants()->where('is_active', false)->get();
        $this->assertCount(1, $elle);
        $this->assertSame('L', $elle->first()->etichetta_corta);

        // Ripensarci è una spunta: la L torna com'era, con le sue righe
        // d'ordine ancora attaccate.
        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id, $taglie['L']->id],
        ]);

        $this->assertSame(3, $listing->variants()->count(), 'Nessun doppione della L.');
    }

    public function test_una_combinazione_spenta_dalla_rigenerazione_non_si_vende_dal_carrello(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        [$buyerUser, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ]);

        $esse = $listing->variants()->get()->first(fn ($v) => $v->etichetta_corta === 'S');

        // refresh(): has_variants l'ha acceso la generazione qui sopra, e
        // l'istanza del test è ancora quella di prima.
        $listing->refresh();

        app(\App\Services\CartService::class)->aggiungi($buyerAccount, $listing, 1, $esse);

        // Mentre la S è nel carrello, il venditore deseleziona la S.
        $this->actingAs($sellerUser)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['M']->id],
        ]);

        $prima = $this->sommaSaldiCircuito();

        try {
            app(\App\Services\CartService::class)->checkout($buyerAccount, $buyerUser);
            $this->fail('La cassa doveva fermarsi su una combinazione spenta.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non è più disponibile', $e->getMessage());
        }

        $this->assertSame($prima, $this->sommaSaldiCircuito(), 'Nessun soldo si è mosso.');
    }

    public function test_il_venditore_scrive_il_prezzo_pieno_e_il_sistema_salva_il_delta(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['XL']);
        $grande = $this->makeVariante($listing, [$taglie['XL']]);

        // Il venditore ragiona in prezzi: scrive "25,50", non "+5,50".
        $this->actingAs($sellerUser)->put(route('portal.shop.variants.update', $listing), [
            'varianti' => [
                $grande->id => ['prezzo' => '25,50', 'scorte' => 8, 'sku' => 'XL-001'],
            ],
        ])->assertSessionHas('portal_success');

        $grande->refresh();
        $this->assertSame(550, $grande->price_delta_ky, '25,50 su una base di 20,00 fa un delta di 5,50.');
        $this->assertSame(8, $grande->stock_quantity);
        $this->assertSame('XL-001', $grande->sku);
        $this->assertSame(2550, $grande->fresh(['listing'])->prezzoEffettivo());
    }

    public function test_la_giacenza_lasciata_vuota_vuol_dire_illimitata(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $media = $this->makeVariante($listing, [$taglie['M']], scorte: 5);

        $this->actingAs($sellerUser)->put(route('portal.shop.variants.update', $listing), [
            'varianti' => [$media->id => ['prezzo' => '20,00', 'scorte' => null]],
        ]);

        $this->assertNull($media->fresh()->stock_quantity);
        $this->assertFalse($media->fresh()->hasLimitedStock());
    }

    public function test_l_admin_gestisce_le_varianti_dei_prodotti_di_qualsiasi_azienda(): void
    {
        // Richiesta di Laura (25/08): dal backoffice si deve poter mettere mano
        // alle varianti dei prodotti delle aziende, non solo dei propri —
        // esattamente come si fa già con "Nuovo prodotto per conto azienda".
        [$admin] = $this->makeBuyer(saldo: 1000);
        $admin->forceFill(['is_super_admin' => true])->save();

        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);

        // Genera le combinazioni...
        $this->actingAs($admin)->post(route('portal.shop.variants.generate', $listing), [
            'valori' => [$taglie['S']->id, $taglie['M']->id],
        ])->assertSessionHas('portal_success');

        $this->assertSame(2, $listing->variants()->count());

        // ...e ne imposta prezzo e giacenza.
        $variante = $listing->variants()->first();
        $this->actingAs($admin)->put(route('portal.shop.variants.update', $listing), [
            'varianti' => [$variante->id => ['prezzo' => '23,00', 'scorte' => 4]],
        ])->assertSessionHas('portal_success');

        $variante->refresh();
        $this->assertSame(300, $variante->price_delta_ky);
        $this->assertSame(4, $variante->stock_quantity);

        // E la pagina si apre normalmente.
        $this->actingAs($admin)->get(route('portal.shop.variants', $listing))
            ->assertOk()
            ->assertSee('Taglia');
    }

    public function test_nessuno_gestisce_le_varianti_dei_prodotti_di_un_altro(): void
    {
        [$company] = $this->makeSeller();
        [, $estraneo] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);

        $this->actingAs($estraneo)
            ->post(route('portal.shop.variants.generate', $listing), ['valori' => [$taglie['M']->id]])
            ->assertRedirect(route('portal.shop.show', $listing));

        $this->assertSame(0, $listing->variants()->count());
    }

    public function test_tolta_l_ultima_combinazione_il_prodotto_torna_semplice(): void
    {
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['M']);
        $media = $this->makeVariante($listing, [$taglie['M']]);
        $this->assertTrue($listing->fresh()->has_variants);

        $this->actingAs($sellerUser)->delete(route('portal.shop.variants.destroy', [$listing, $media]));

        // Altrimenti resterebbe "variabile" senza varianti: incomprabile.
        $this->assertFalse($listing->fresh()->has_variants);
        $this->assertFalse($listing->fresh()->isVariabile());
    }

    public function test_l_admin_non_puo_eliminare_un_attributo_gia_in_uso(): void
    {
        [$admin] = $this->makeBuyer(saldo: 1000);
        $admin->forceFill(['is_super_admin' => true])->save();

        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $taglie = $this->makeAttributo('Taglia', ['M']);
        $this->makeVariante($listing, [$taglie['M']]);

        $attributo = $taglie['M']->attribute;

        $this->actingAs($admin)
            ->delete(route('admin.listing-attributes.destroy', $attributo))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('listing_attributes', ['id' => $attributo->id]);

        // Spegnerlo invece si può: sparisce dai form dei venditori e i prodotti
        // che lo usano continuano a funzionare.
        $this->actingAs($admin)->patch(route('admin.listing-attributes.toggle', $attributo));
        $this->assertFalse($attributo->fresh()->is_active);
        $this->assertTrue($listing->fresh()->isVariabile());
    }

    // =========================================================================
    // 8. Il denaro, anche qui
    // =========================================================================

    public function test_anche_con_le_varianti_il_denaro_non_si_crea_e_non_si_distrugge(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 1000, kyPercentage: 100);

        $taglie = $this->makeAttributo('Taglia', ['XL']);
        $grande = $this->makeVariante($listing, [$taglie['XL']], deltaKy: 750);

        $prima = $this->sommaSaldiCircuito();

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['variant_id' => $grande->id, 'quantity' => 3]);

        $this->assertSame($prima, $this->sommaSaldiCircuito());
        $this->assertSame(5250, Order::query()->sole()->total_ky);
    }
}
