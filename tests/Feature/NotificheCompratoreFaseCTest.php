<?php

namespace Tests\Feature;

use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Notifications\NewMarketplaceOrderNotification;
use App\Notifications\OrderEuroQuotaReminderNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderShippedNotification;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE C — chi compra smette di restare in silenzio (27/08/2026).
 *
 * Fino a ieri il circuito avvisava soltanto il venditore. Chi comprava non
 * riceveva niente: né una conferma, né il numero d'ordine, né la notizia che
 * il pacco era partito.
 *
 * Le due cose che questi test difendono:
 *   1. **Le due strade d'acquisto avvisano le stesse persone.** Carrello e
 *      "Compra ora" sono divergiti una volta (l'audit del 26/08); qui si
 *      controlla che non ricapiti sulle notifiche.
 *   2. **Il sollecito parte una volta sola.** Trenta email identiche non
 *      convincono nessuno più della prima: fanno finire il circuito nello spam.
 *
 * Importi in CENTESIMI.
 */
class NotificheCompratoreFaseCTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // Conferma d'ordine
    // =========================================================================

    public function test_chi_compra_dal_carrello_riceve_la_conferma(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 1]);
        $this->actingAs($buyer)->post(route('portal.cart.checkout'), ['accetto_condizioni' => '1']);

        Notification::assertSentTo($buyer, OrderPlacedNotification::class);
    }

    public function test_chi_compra_con_compra_ora_riceve_la_stessa_conferma(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        // È il punto: le due strade devono avvisare le stesse persone. Sono
        // già divergite una volta.
        Notification::assertSentTo($buyer, OrderPlacedNotification::class);
    }

    public function test_il_venditore_continua_a_ricevere_il_suo_avviso(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        // Aggiungere la notifica al compratore non deve averla tolta a lui.
        Notification::assertSentTo($sellerUser, NewMarketplaceOrderNotification::class);
    }

    public function test_la_conferma_non_va_a_chi_non_ha_comprato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$estraneo] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        Notification::assertNotSentTo($estraneo, OrderPlacedNotification::class);
    }

    // =========================================================================
    // Ordine spedito
    // =========================================================================

    public function test_quando_il_venditore_segna_spedito_il_compratore_viene_avvisato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), [
            'stato'         => Order::STATUS_SHIPPED,
            'carrier'       => 'BRT',
            'tracking_code' => 'ABC123',
        ]);

        Notification::assertSentTo($buyer, OrderShippedNotification::class,
            fn (OrderShippedNotification $n) => $n->order->tracking_code === 'ABC123');
    }

    public function test_gli_altri_passaggi_non_generano_email(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        // "In preparazione" e "consegnato" restano visibili nella pagina
        // ordini: non meritano una email a testa.
        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_PREPARING]);
        Notification::assertNotSentTo($buyer, OrderShippedNotification::class);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_SHIPPED]);
        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_DELIVERED]);

        Notification::assertSentToTimes($buyer, OrderShippedNotification::class, 1);
    }

    // =========================================================================
    // Il sollecito della quota in euro
    // =========================================================================

    public function test_un_ordine_fermo_da_abbastanza_giorni_viene_sollecitato(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 5);

        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();

        Notification::assertSentTo($buyer, OrderEuroQuotaReminderNotification::class);
        $this->assertNotNull($ordine->fresh()->euro_reminder_sent_at);
    }

    public function test_un_ordine_di_ieri_non_viene_ancora_sollecitato(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 1);

        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();

        Notification::assertNotSentTo($buyer, OrderEuroQuotaReminderNotification::class);
        $this->assertNull($ordine->fresh()->euro_reminder_sent_at);
    }

    public function test_il_sollecito_parte_una_volta_sola(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 5);

        // Trenta notti di seguito: chi ha un ordine fermo da un mese non deve
        // ricevere trenta email identiche.
        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();
        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();
        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();

        Notification::assertSentToTimes($buyer, OrderEuroQuotaReminderNotification::class, 1);
    }

    public function test_non_si_sollecita_chi_ha_gia_pagato_e_aspetta_la_conferma(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 5);

        // Bonifico arrivato, manca solo che il venditore lo confermi:
        // sollecitare qui vorrebbe dire dare del moroso a chi ha già pagato.
        $ordine->payment->update(['status' => MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION]);

        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();

        Notification::assertNotSentTo($buyer, OrderEuroQuotaReminderNotification::class);
    }

    public function test_non_si_sollecita_un_ordine_tutto_in_ky(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $ordine->forceFill(['placed_at' => now()->subDays(30)])->save();

        $this->artisan('shop:solleciti-quota-euro')->assertSuccessful();

        Notification::assertNotSentTo($buyer, OrderEuroQuotaReminderNotification::class);
    }

    public function test_la_prova_a_vuoto_non_manda_niente_e_non_segna_niente(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 5);

        $this->artisan('shop:solleciti-quota-euro --dry-run')->assertSuccessful();

        Notification::assertNotSentTo($buyer, OrderEuroQuotaReminderNotification::class);
        $this->assertNull($ordine->fresh()->euro_reminder_sent_at);
    }

    public function test_la_soglia_dei_giorni_si_puo_cambiare(): void
    {
        [$ordine, $buyer] = $this->ordineFermoInAttesaDiEuro(giorniFa: 2);

        $this->artisan('shop:solleciti-quota-euro --giorni=1')->assertSuccessful();

        Notification::assertSentTo($buyer, OrderEuroQuotaReminderNotification::class);
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    private function ordina($buyerAccount, $buyer, $listing): Order
    {
        return app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );
    }

    /** @return array{0: Order, 1: \App\Models\User} */
    private function ordineFermoInAttesaDiEuro(int $giorniFa): array
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 75);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->status);

        $ordine->forceFill(['placed_at' => now()->subDays($giorniFa)])->save();

        return [$ordine->fresh(['payment']), $buyer];
    }
}
