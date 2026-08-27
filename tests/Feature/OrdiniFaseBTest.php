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
    // La voce di menu
    // =========================================================================

    /**
     * Segnalato da Laura il 27/08: da utenza privata "I miei ordini" non
     * compariva nel menu Circuito.
     *
     * Era il cancello sbagliato. Shop e Carrello stanno dietro al permesso
     * marketplace, ed e' giusto; lo storico dei PROPRI acquisti no. Chi ha
     * comprato deve poterlo rivedere anche il giorno in cui gli venisse tolto
     * quel permesso — le ricevute non si tolgono.
     */
    public function test_i_miei_ordini_si_vede_anche_senza_permesso_marketplace(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('portal.orders.index'), $html,
            'Lo storico dei propri acquisti non dipende dal permesso marketplace.');

        // E che il permesso davvero non ce l'abbia lo si dice qui, non
        // cercando l'assenza di "/shop" nell'HTML: quella stringa compare
        // comunque dentro "/shop/carrello" dell'icona del carrello in alto.
        $this->assertFalse($buyer->canAccessMarketplace());
    }

    public function test_ordini_ricevuti_resta_riservato_a_chi_ha_un_negozio(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);

        $html = $this->actingAs($buyer)->get(route('portal.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('portal.sales.index'), $html);
    }

    // =========================================================================
    // L'admin gestisce per conto delle aziende (richiesta di Laura, 27/08)
    // =========================================================================

    public function test_l_admin_vede_gli_ordini_di_tutti_i_negozi(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$uno] = $this->makeSeller();
        [$due] = $this->makeSeller();
        $this->ordina($buyerAccount, $buyer, $this->makeListing($uno, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba del primo']));
        $this->ordina($buyerAccount, $buyer, $this->makeListing($due, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba del secondo']));

        $this->actingAs($this->makeAdmin())->get(route('portal.sales.index'))
            ->assertOk()
            ->assertSee('Roba del primo')
            ->assertSee('Roba del secondo')
            // E deve sapere di CHI sono, altrimenti non puo' gestirli per loro conto.
            ->assertSee($uno->name)
            ->assertSee($due->name);
    }

    public function test_l_admin_puo_filtrare_per_negozio(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$uno] = $this->makeSeller();
        [$due] = $this->makeSeller();
        $this->ordina($buyerAccount, $buyer, $this->makeListing($uno, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba del primo']));
        $this->ordina($buyerAccount, $buyer, $this->makeListing($due, prezzo: 2000, kyPercentage: 100, extra: ['title' => 'Roba del secondo']));

        $this->actingAs($this->makeAdmin())
            ->get(route('portal.sales.index', ['company' => $uno->id]))
            ->assertOk()
            ->assertSee('Roba del primo')
            ->assertDontSee('Roba del secondo');
    }

    public function test_l_admin_puo_far_avanzare_un_ordine_per_conto_del_negozio(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $saldo = (int) $buyerAccount->fresh()->available_balance;
        $movimenti = Transfer::count();

        $this->actingAs($this->makeAdmin())
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_SHIPPED])
            ->assertSessionHas('portal_success');

        $this->assertSame(Order::STATUS_SHIPPED, $ordine->fresh()->status);

        // Vale anche per l'admin: correggere uno stato non muove denaro.
        $this->assertSame($saldo, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame($movimenti, Transfer::count());
    }

    /**
     * E' la ragione per cui l'admin esiste in questa pagina: rimediare a un
     * "spedito" premuto per sbaglio. Il venditore non puo' farlo (va solo
     * avanti), e questa e' l'altra meta' di quella decisione.
     */
    public function test_l_admin_puo_riportare_indietro_un_ordine_e_le_date_si_puliscono(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($sellerUser)->post(route('portal.sales.status', $ordine), [
            'stato' => Order::STATUS_SHIPPED, 'tracking_code' => 'SBAGLIATO',
        ]);
        $this->assertNotNull($ordine->fresh()->shipped_at);

        $this->actingAs($this->makeAdmin())
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_PREPARING])
            ->assertSessionHas('portal_success');

        $ordine->refresh();
        $this->assertSame(Order::STATUS_PREPARING, $ordine->status);
        // Una data di spedizione su un ordine "in preparazione" racconterebbe
        // una storia falsa nella cronologia.
        $this->assertNull($ordine->shipped_at);
    }

    public function test_nemmeno_l_admin_puo_toccare_un_ordine_che_aspetta_gli_euro(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 75);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        $this->actingAs($this->makeAdmin())
            ->post(route('portal.sales.status', $ordine), ['stato' => Order::STATUS_PREPARING])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->fresh()->status);
    }

    public function test_nemmeno_l_admin_puo_annullare_da_qui(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);

        // Annullamenti e rimborsi muovono denaro: sono il giro 2, e non devono
        // entrare da una scorciatoia.
        foreach ([Order::STATUS_CANCELLED, Order::STATUS_REFUNDED] as $stato) {
            $this->actingAs($this->makeAdmin())
                ->post(route('portal.sales.status', $ordine), ['stato' => $stato])
                ->assertSessionHas('portal_error');
        }

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    public function test_la_traccia_distingue_l_admin_dal_venditore(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('portal.sales.status', $ordine), [
            'stato' => Order::STATUS_SHIPPED,
        ]);

        $log = AuditLog::query()->where('event', 'order.status.changed')->sole();
        $this->assertSame($admin->id, (int) $log->actor_user_id);
        $this->assertTrue($log->context['per_conto_del_negozio']);
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    private function makeAdmin(): \App\Models\User
    {
        return \App\Models\User::create([
            'name'                => 'Admin del circuito',
            'email'               => 'admin-' . \Illuminate\Support\Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'admin',
            'is_active'           => true,
            'is_super_admin'      => true,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);
    }

    private function ordina($buyerAccount, $buyer, $listing, int $quantita = 1): Order
    {
        return app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => $quantita]],
        );
    }
}
