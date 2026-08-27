<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Transfer;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE B — gli ordini, da tutte e due le parti (27/08/2026).
 *
 * Le due cose che questi test difendono più di ogni altra:
 *
 *   1. **Cambiare stato non muove un centesimo.** "Spedito" e "consegnato"
 *      sono etichette; l'addebito è già avvenuto alla cassa. Se un giorno
 *      qualcuno agganciasse del denaro a un passaggio di stato, questi test
 *      devono cadere.
 *   2. **Nessuno vede l'ordine di un altro.** Né il compratore sbagliato, né
 *      un venditore che non è quello dell'ordine.
 *
 * Importi in CENTESIMI.
 */
class OrdiniFaseBTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // La pagina di chi compra
    // =========================================================================

    public function test_il_compratore_ritrova_il_suo_ordine_col_numero(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Lampada di sale']);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($buyer)->get(route('portal.orders.index'))
            ->assertOk()
            ->assertSee('Lampada di sale')
            ->assertSee($ordine->numero);
    }

    public function test_l_elenco_e_vuoto_con_garbo_quando_non_si_e_comprato_niente(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($buyer)->get(route('portal.orders.index'))
            ->assertOk()
            ->assertSee('Non hai ancora ordini');
    }

    public function test_il_dettaglio_mostra_indirizzo_righe_e_stato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title'         => 'Zaino da trekking',
            'delivery_type' => \App\Models\Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing, quantita: 2);

        $this->actingAs($buyer)->get(route('portal.orders.show', $ordine))
            ->assertOk()
            ->assertSee('Zaino da trekking')
            ->assertSee('Via Roma 1')
            ->assertSee('Milano')
            ->assertSee('Pagato');
    }

    public function test_un_ordine_di_un_altro_compratore_e_vietato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$estraneo] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($estraneo)->get(route('portal.orders.show', $ordine))->assertForbidden();
    }

    public function test_nell_elenco_si_vedono_solo_i_propri_ordini(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$altro, $altroAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $mio    = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Cosa mia']);
        $altrui = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Cosa altrui']);

        $this->ordina($buyerAccount, $buyer, $mio);
        $this->ordina($altroAccount, $altro, $altrui);

        $this->actingAs($buyer)->get(route('portal.orders.index'))
            ->assertOk()
            ->assertSee('Cosa mia')
            ->assertDontSee('Cosa altrui');
    }

    // =========================================================================
    // La pagina di chi vende
    // =========================================================================

    public function test_il_venditore_vede_gli_ordini_ricevuti_col_destinatario(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title'         => 'Tappeto persiano',
            'delivery_type' => \App\Models\Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->get(route('portal.sales.index'))
            ->assertOk()
            ->assertSee('Tappeto persiano')
            ->assertSee('Mario Rossi');
    }

    public function test_un_venditore_non_vede_gli_ordini_di_un_altro_negozio(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$mio]  = $this->makeSeller();
        [$suo, $altroSeller] = $this->makeSeller();
        $listing = $this->makeListing($mio, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba mia']);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($altroSeller)->get(route('portal.sales.index'))
            ->assertOk()
            ->assertDontSee('Roba mia');

        $this->actingAs($altroSeller)->get(route('portal.sales.show', $ordine))->assertForbidden();
    }

    public function test_il_compratore_non_puo_aprire_la_pagina_del_venditore(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($buyer)->get(route('portal.sales.show', $ordine))->assertForbidden();
    }

    // =========================================================================
    // I passaggi di stato — e il fatto che NON muovono soldi
    // =========================================================================

    public function test_il_venditore_porta_l_ordine_da_pagato_a_consegnato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $saldoCompratore = (int) $buyerAccount->fresh()->available_balance;
        $movimenti = Transfer::count();

        foreach ([Order::STATUS_PREPARING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED] as $stato) {
            $this->actingAs($sellerUser)
                ->post(route('portal.sales.status', $ordine), ['stato' => $stato])
                ->assertSessionHas('portal_success');

            $this->assertSame($stato, $ordine->fresh()->status);
        }

        // IL PUNTO DI TUTTO: tre cambi di stato, zero denaro mosso.
        $this->assertSame($saldoCompratore, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame($movimenti, Transfer::count());

        $ordine->refresh();
        $this->assertNotNull($ordine->shipped_at);
        $this->assertNotNull($ordine->delivered_at);
    }

    public function test_segnando_spedito_si_registrano_corriere_e_tracking(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), [
            'stato'         => Order::STATUS_SHIPPED,
            'carrier'       => 'BRT',
            'tracking_code' => 'ABC123456',
        ]);

        $ordine->refresh();
        $this->assertSame('BRT', $ordine->carrier);
        $this->assertSame('ABC123456', $ordine->tracking_code);

        // E il compratore lo vede dalla sua pagina, che è il motivo per cui
        // glielo abbiamo chiesto.
        $this->actingAs($buyer)->get(route('portal.orders.show', $ordine))
            ->assertOk()
            ->assertSee('ABC123456');
    }

    public function test_non_si_salta_dritti_a_consegnato_da_pagato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_DELIVERED])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    public function test_non_si_torna_indietro(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $ordine->forceFill(['status' => Order::STATUS_SHIPPED])->save();

        // Un negozio che torna indietro sta rimediando a un errore: per quello
        // c'è l'admin, non il bottone del venditore.
        $this->actingAs($sellerUser)
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_PREPARING])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_SHIPPED, $ordine->fresh()->status);
    }

    public function test_un_ordine_che_aspetta_gli_euro_non_si_puo_spedire(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 75);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->status);

        // È una protezione per il venditore: non deve preparare merce per un
        // ordine che non è stato saldato per intero.
        $this->actingAs($sellerUser)
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_PREPARING])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->fresh()->status);
    }

    public function test_un_estraneo_non_puo_cambiare_lo_stato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        [, $altroSeller] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($altroSeller)
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_SHIPPED])
            ->assertForbidden();

        $this->actingAs($buyer)
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_SHIPPED])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    // =========================================================================
    // Chi ha premuto il bottone
    // =========================================================================

    public function test_ogni_cambio_di_stato_lascia_traccia_di_chi_l_ha_fatto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), [
            'stato' => Order::STATUS_SHIPPED,
        ]);

        // Il giorno che un compratore contesta una consegna, l'unica difesa è
        // sapere chi ha premuto quel bottone e quando.
        $log = AuditLog::query()->where('event', 'order.status.changed')->sole();

        $this->assertSame($sellerUser->id, (int) $log->actor_user_id);
        $this->assertSame(Order::class, $log->auditable_type);
        $this->assertSame($ordine->id, (int) $log->auditable_id);
        $this->assertSame(Order::STATUS_PAID, $log->context['da']);
        $this->assertSame(Order::STATUS_SHIPPED, $log->context['a']);
    }

    public function test_un_passaggio_rifiutato_non_lascia_traccia(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), [
            'stato' => Order::STATUS_DELIVERED,
        ]);

        $this->assertSame(0, AuditLog::where('event', 'order.status.changed')->count());
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    private function ordina($buyerAccount, $buyer, $listing, int $quantita = 1): Order
    {
        return app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => $quantita]],
        );
    }
}
