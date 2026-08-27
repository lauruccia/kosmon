<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditLimit;
use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\OrderReturnRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderReturnDecidedNotification;
use App\Notifications\OrderReturnRequestedNotification;
use App\Services\OrderService;
use App\Services\TransferBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * GIRO 2 DELLA FASE B — annullamenti e resi (27/08/2026).
 *
 * Qui, per la prima volta nella pagina degli ordini, i soldi tornano indietro.
 * Le quattro cose che questi test difendono:
 *
 *   1. **Nessun rimborso doppio.** Né annullando due volte, né annullando e
 *      poi rimborsando a mano dai movimenti. E nemmeno la merce deve tornare
 *      in magazzino due volte: è il motivo per cui esiste `stock_restored_at`.
 *   2. **Chi può fare cosa.** Annulla il venditore (e l'admin per conto suo),
 *      chiede il reso il compratore, risponde il venditore. Mai il contrario.
 *   3. **La regola del fido** (decisione di Laura, 27/08). Il rimborso può far
 *      scendere il venditore sotto zero fino al fido concesso, e non oltre.
 *      `refundMerchant()` da solo non lo controlla: se questi test passassero
 *      anche senza il controllo, il conto di un negozio potrebbe finire a meno
 *      infinito.
 *   4. **La finestra del reso.** Quattordici giorni dalla consegna — e se il
 *      venditore non segna mai "consegnato", dalla spedizione più la stima:
 *      un venditore distratto non deve poter far scadere il diritto altrui.
 *
 * Importi in CENTESIMI.
 */
class AnnullamentiEResiTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // Annullamento: il caso normale
    // =========================================================================

    public function test_il_venditore_annulla_e_i_ky_tornano_al_compratore(): void
    {
        [$ordine, $buyer, $buyerAccount, $sellerUser, $sellerAccount] = $this->ordineDaAnnullare();

        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(2000, (int) $sellerAccount->fresh()->available_balance);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Prodotto non più disponibile'])
            ->assertRedirect();

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, (int) $sellerAccount->fresh()->available_balance);

        $aggiornato = $ordine->fresh();
        $this->assertSame(Order::STATUS_CANCELLED, $aggiornato->status);
        $this->assertSame('Prodotto non più disponibile', $aggiornato->cancel_reason);
        $this->assertSame($sellerUser->id, (int) $aggiornato->cancelled_by_user_id);
        $this->assertNotNull($aggiornato->cancelled_at);
        $this->assertNotNull($aggiornato->refund_transfer_id);
    }

    public function test_annullando_la_merce_torna_in_magazzino(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 7]);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing, quantita: 3);
        $this->assertSame(4, (int) $listing->fresh()->stock_quantity);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Magazzino sbagliato']);

        $this->assertSame(7, (int) $listing->fresh()->stock_quantity);
        $this->assertNotNull($ordine->fresh()->stock_restored_at);
    }

    public function test_annullando_la_quota_in_euro_non_saldata_viene_chiusa(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 4000, kyPercentage: 50);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $ordine->status);
        $this->assertSame(MarketplaceOrderPayment::STATUS_PENDING, $ordine->fresh()->payment->status);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Non riesco a spedire']);

        // Chiusa: altrimenti il compratore continuerebbe a vedere il bottone
        // "salda" e i solleciti notturni continuerebbero a partire.
        $this->assertSame(MarketplaceOrderPayment::STATUS_CANCELLED, $ordine->fresh()->payment->status);
        $this->assertSame(Order::STATUS_CANCELLED, $ordine->fresh()->status);
    }

    public function test_il_compratore_viene_avvisato_dell_annullamento(): void
    {
        [$ordine, $buyer, , $sellerUser] = $this->ordineDaAnnullare();

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Prodotto rotto in magazzino']);

        Notification::assertSentTo($buyer, OrderCancelledNotification::class);
    }

    public function test_l_annullamento_finisce_nel_registro_con_l_importo(): void
    {
        [$ordine, , , $sellerUser] = $this->ordineDaAnnullare();

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Errore di prezzo']);

        $log = AuditLog::query()->where('event', 'order.cancelled')->sole();
        $this->assertSame($sellerUser->id, (int) $log->actor_user_id);
        $this->assertSame(2000, (int) $log->context['rimborso_ky']);
        $this->assertFalse($log->context['per_conto_del_negozio']);
    }

    public function test_l_admin_annulla_per_conto_del_negozio_e_resta_scritto(): void
    {
        [$ordine, , $buyerAccount] = $this->ordineDaAnnullare();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Richiesta del cliente in assistenza'])
            ->assertRedirect();

        $this->assertSame(Order::STATUS_CANCELLED, $ordine->fresh()->status);
        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);

        $log = AuditLog::query()->where('event', 'order.cancelled')->sole();
        $this->assertTrue($log->context['per_conto_del_negozio']);
        $this->assertSame($admin->id, (int) $log->actor_user_id);
    }

    // =========================================================================
    // Annullamento: chi non può, e quando non si può
    // =========================================================================

    public function test_un_ordine_gia_spedito_non_si_annulla(): void
    {
        [$ordine, , $buyerAccount, $sellerUser] = $this->ordineDaAnnullare();
        $ordine->forceFill(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()])->save();

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Ci ho ripensato'])
            ->assertSessionHas('portal_error', fn ($messaggio) => str_contains($messaggio, 'in viaggio'));

        $this->assertSame(Order::STATUS_SHIPPED, $ordine->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
    }

    public function test_un_ordine_consegnato_non_si_annulla(): void
    {
        [$ordine, , , $sellerUser] = $this->ordineDaAnnullare();
        $ordine->forceFill(['status' => Order::STATUS_DELIVERED, 'delivered_at' => now()])->save();

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Ci ho ripensato'])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_DELIVERED, $ordine->fresh()->status);
    }

    public function test_il_compratore_non_puo_annullare_il_proprio_ordine(): void
    {
        [$ordine, $buyer, $buyerAccount] = $this->ordineDaAnnullare();

        $this->actingAs($buyer)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Non lo voglio più'])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
    }

    public function test_un_altro_negozio_non_puo_annullare_l_ordine_altrui(): void
    {
        [$ordine] = $this->ordineDaAnnullare();
        [, $estraneo] = $this->makeSeller();

        $this->actingAs($estraneo)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Curiosità'])
            ->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    public function test_il_motivo_dell_annullamento_e_obbligatorio(): void
    {
        [$ordine, , , $sellerUser] = $this->ordineDaAnnullare();

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
    }

    public function test_annullare_due_volte_non_rimborsa_due_volte(): void
    {
        [$ordine, , $buyerAccount, $sellerUser] = $this->ordineDaAnnullare();

        $this->actingAs($sellerUser)->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Primo tentativo']);
        $this->actingAs($sellerUser)->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Secondo tentativo'])
            ->assertSessionHas('portal_error');

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(1, Transfer::query()->where('kind', 'portal_refund')->count());
    }

    // =========================================================================
    // La regola del fido
    // =========================================================================

    public function test_senza_ky_e_senza_fido_il_rimborso_si_ferma_e_spiega(): void
    {
        [$ordine, , $buyerAccount, $sellerUser, $sellerAccount] = $this->ordineDaAnnullare();

        // Il negozio ha già speso quello che aveva incassato.
        $this->svuotaIlConto($sellerAccount);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Provo comunque'])
            ->assertSessionHas('portal_error', fn ($messaggio) => str_contains($messaggio, 'Ricarica il conto'));

        // Niente a metà: l'ordine resta pagato e il compratore non ha ricevuto
        // niente. Meglio un annullamento che non parte di un ordine "annullato"
        // con i soldi ancora dall'altra parte.
        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, (int) $sellerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::query()->where('kind', 'portal_refund')->count());
    }

    public function test_col_fido_capiente_il_rimborso_parte_anche_in_negativo(): void
    {
        [$ordine, , $buyerAccount, $sellerUser, $sellerAccount] = $this->ordineDaAnnullare();

        $this->svuotaIlConto($sellerAccount);
        CreditLimit::create([
            'account_id'   => $sellerAccount->id,
            'credit_limit' => 5000,
            'status'       => 'active',
            'approved_at'  => now(),
        ]);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Rimborso col fido'])
            ->assertRedirect();

        $this->assertSame(Order::STATUS_CANCELLED, $ordine->fresh()->status);
        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(-2000, (int) $sellerAccount->fresh()->available_balance);
    }

    public function test_oltre_il_fido_il_rimborso_si_ferma(): void
    {
        [$ordine, , $buyerAccount, $sellerUser, $sellerAccount] = $this->ordineDaAnnullare();

        // Fido da 1.000 su un rimborso da 2.000: non basta.
        $this->svuotaIlConto($sellerAccount);
        CreditLimit::create([
            'account_id'   => $sellerAccount->id,
            'credit_limit' => 1000,
            'status'       => 'active',
            'approved_at'  => now(),
        ]);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Provo col fido corto'])
            ->assertSessionHas('portal_error');

        $this->assertSame(Order::STATUS_PAID, $ordine->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
    }

    public function test_l_annullamento_non_crea_ne_distrugge_denaro(): void
    {
        [$ordine, , , $sellerUser] = $this->ordineDaAnnullare();
        $prima = $this->sommaSaldiCircuito();

        $this->actingAs($sellerUser)->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Verifica contabile']);

        $this->assertSame($prima, $this->sommaSaldiCircuito());
    }

    // =========================================================================
    // Il reso: la richiesta
    // =========================================================================

    public function test_il_compratore_chiede_il_reso_di_un_ordine_consegnato(): void
    {
        [$ordine, $buyer, , , , $company] = $this->ordineConsegnato();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'È arrivato con il vetro rotto'])
            ->assertRedirect();

        $pratica = OrderReturnRequest::query()->sole();
        $this->assertSame(OrderReturnRequest::STATUS_PENDING, $pratica->status);
        $this->assertSame($buyer->id, (int) $pratica->requested_by_user_id);
        $this->assertSame($ordine->id, (int) $pratica->order_id);

        // Nessun soldo si è mosso: è una richiesta, non un prelievo.
        $this->assertSame(0, Transfer::query()->where('kind', 'portal_refund')->count());
        $this->assertSame(Order::STATUS_DELIVERED, $ordine->fresh()->status);

        Notification::assertSentTo(
            $company->primaryBusinessAccount()->ownerUser,
            OrderReturnRequestedNotification::class
        );
    }

    public function test_non_si_chiede_un_reso_prima_della_spedizione(): void
    {
        [$ordine, $buyer] = $this->ordineDaAnnullare();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'Ci ho ripensato del tutto'])
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'spedito'));

        $this->assertSame(0, OrderReturnRequest::query()->count());
    }

    public function test_non_si_chiede_due_volte_lo_stesso_reso(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();

        $this->actingAs($buyer)->post(route('portal.orders.return', $ordine), ['motivo' => 'Prodotto difettoso']);
        $this->actingAs($buyer)->post(route('portal.orders.return', $ordine), ['motivo' => 'Insisto molto'])
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'già una richiesta'));

        $this->assertSame(1, OrderReturnRequest::query()->count());
    }

    public function test_dopo_quattordici_giorni_dalla_consegna_il_reso_e_chiuso(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();
        $ordine->forceFill(['delivered_at' => now()->subDays(15)])->save();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'Troppo tardi purtroppo'])
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'scaduto'));

        $this->assertSame(0, OrderReturnRequest::query()->count());
    }

    public function test_se_il_venditore_non_segna_consegnato_la_finestra_parte_dalla_spedizione(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();

        // Spedito venti giorni fa e mai segnato consegnato: senza la stima, il
        // conto non partirebbe mai e il compratore potrebbe chiedere il reso
        // per sempre.
        $ordine->forceFill([
            'status'       => Order::STATUS_SHIPPED,
            'delivered_at' => null,
            'shipped_at'   => now()->subDays(20),
        ])->save();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'Non funziona niente'])
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'scaduto'));

        $this->assertSame(0, OrderReturnRequest::query()->count());
    }

    public function test_appena_spedito_il_reso_si_puo_gia_chiedere(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();
        $ordine->forceFill([
            'status'       => Order::STATUS_SHIPPED,
            'delivered_at' => null,
            'shipped_at'   => now()->subDay(),
        ])->save();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'Ho sbagliato la taglia'])
            ->assertRedirect();

        $this->assertSame(1, OrderReturnRequest::query()->count());
    }

    public function test_il_motivo_del_reso_deve_dire_qualcosa(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();

        $this->actingAs($buyer)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'boh'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, OrderReturnRequest::query()->count());
    }

    public function test_nessuno_chiede_un_reso_sull_ordine_di_un_altro(): void
    {
        [$ordine] = $this->ordineConsegnato();
        [$estraneo] = $this->makeBuyer(saldo: 100000);

        $this->actingAs($estraneo)
            ->post(route('portal.orders.return', $ordine), ['motivo' => 'Provo a vedere se passa'])
            ->assertForbidden();

        $this->assertSame(0, OrderReturnRequest::query()->count());
    }

    // =========================================================================
    // Il reso: la risposta
    // =========================================================================

    public function test_accettando_il_reso_i_ky_tornano_e_la_merce_rientra(): void
    {
        [$ordine, $buyer, $buyerAccount, $sellerUser, $sellerAccount, , $listing] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);

        $scorteDopoAcquisto = (int) $listing->fresh()->stock_quantity;

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.return.decide', [$ordine, $pratica]), [
                'esito' => OrderReturnRequest::STATUS_ACCEPTED,
                'nota'  => 'Rispedisci pure, ti rimborso subito',
            ])
            ->assertRedirect();

        $this->assertSame(OrderReturnRequest::STATUS_ACCEPTED, $pratica->fresh()->status);
        $this->assertSame(Order::STATUS_REFUNDED, $ordine->fresh()->status);
        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, (int) $sellerAccount->fresh()->available_balance);
        $this->assertSame($scorteDopoAcquisto + 1, (int) $listing->fresh()->stock_quantity);

        Notification::assertSentTo($buyer, OrderReturnDecidedNotification::class);
    }

    public function test_rifiutando_il_reso_non_si_muove_un_centesimo(): void
    {
        [$ordine, $buyer, $buyerAccount, $sellerUser] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.return.decide', [$ordine, $pratica]), [
                'esito' => OrderReturnRequest::STATUS_REJECTED,
                'nota'  => 'Il prodotto risulta usato e senza imballo',
            ])
            ->assertRedirect();

        $this->assertSame(OrderReturnRequest::STATUS_REJECTED, $pratica->fresh()->status);
        $this->assertSame(Order::STATUS_DELIVERED, $ordine->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::query()->where('kind', 'portal_refund')->count());

        Notification::assertSentTo($buyer, OrderReturnDecidedNotification::class);
    }

    public function test_un_rifiuto_senza_motivo_non_passa(): void
    {
        [$ordine, $buyer, , $sellerUser] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.return.decide', [$ordine, $pratica]), [
                'esito' => OrderReturnRequest::STATUS_REJECTED,
                'nota'  => '',
            ])
            ->assertSessionHasErrors('nota');

        $this->assertSame(OrderReturnRequest::STATUS_PENDING, $pratica->fresh()->status);
    }

    public function test_il_compratore_non_decide_il_proprio_reso(): void
    {
        [$ordine, $buyer, $buyerAccount] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);

        $this->actingAs($buyer)
            ->post(route('portal.sales.return.decide', [$ordine, $pratica]), [
                'esito' => OrderReturnRequest::STATUS_ACCEPTED,
            ])
            ->assertForbidden();

        $this->assertSame(OrderReturnRequest::STATUS_PENDING, $pratica->fresh()->status);
        $this->assertSame(98000, (int) $buyerAccount->fresh()->available_balance);
    }

    public function test_l_admin_puo_rispondere_al_posto_del_negozio(): void
    {
        [$ordine, $buyer, $buyerAccount] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->post(route('portal.sales.return.decide', [$ordine, $pratica]), [
                'esito' => OrderReturnRequest::STATUS_ACCEPTED,
                'nota'  => 'Chiuso dall\'assistenza del circuito',
            ])
            ->assertRedirect();

        $this->assertSame(OrderReturnRequest::STATUS_ACCEPTED, $pratica->fresh()->status);
        $this->assertTrue((bool) $pratica->fresh()->decided_by_admin);
        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
    }

    public function test_una_pratica_di_un_altro_ordine_non_si_chiude_da_qui(): void
    {
        [$ordine, $buyer, , $sellerUser] = $this->ordineConsegnato();
        [$altroOrdine, $altroBuyer] = $this->ordineConsegnato();
        $praticaAltrui = $this->chiediReso($altroOrdine, $altroBuyer);

        $this->actingAs($sellerUser)
            ->post(route('portal.sales.return.decide', [$ordine, $praticaAltrui]), [
                'esito' => OrderReturnRequest::STATUS_ACCEPTED,
            ])
            ->assertNotFound();

        $this->assertSame(OrderReturnRequest::STATUS_PENDING, $praticaAltrui->fresh()->status);
    }

    public function test_accettare_due_volte_non_rimborsa_due_volte(): void
    {
        [$ordine, $buyer, $buyerAccount, $sellerUser] = $this->ordineConsegnato();
        $pratica = $this->chiediReso($ordine, $buyer);

        $dati = ['esito' => OrderReturnRequest::STATUS_ACCEPTED, 'nota' => 'Va bene'];

        $this->actingAs($sellerUser)->post(route('portal.sales.return.decide', [$ordine, $pratica]), $dati);
        $this->actingAs($sellerUser)->post(route('portal.sales.return.decide', [$ordine, $pratica]), $dati)
            ->assertSessionHas('portal_error', fn ($m) => str_contains($m, 'già stata chiusa'));

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(1, Transfer::query()->where('kind', 'portal_refund')->count());
    }

    // =========================================================================
    // Il caso che tiene in piedi `stock_restored_at`
    // =========================================================================

    public function test_annullato_e_poi_rimborsato_a_mano_la_merce_non_torna_due_volte(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 5]);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing, quantita: 2);
        $this->assertSame(3, (int) $listing->fresh()->stock_quantity);

        $this->actingAs($sellerUser)->post(route('portal.sales.cancel', $ordine), ['motivo' => 'Fuori catalogo']);
        $this->assertSame(5, (int) $listing->fresh()->stock_quantity);

        // Adesso qualcuno passa dai movimenti e chiama la vecchia strada. Il
        // rimborso è già stato fatto per intero, ma il metodo va chiamato lo
        // stesso: è quello che succede in PortalController::refundSubmit().
        app(OrderService::class)->ripristinaScorteDopoRimborso($ordine->fresh()->transfer);

        $this->assertSame(5, (int) $listing->fresh()->stock_quantity);
        $this->assertSame(Order::STATUS_CANCELLED, $ordine->fresh()->status);
    }

    public function test_il_rimborso_dai_movimenti_marca_le_scorte_come_restituite(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 5]);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing, quantita: 2);
        $transfer = $ordine->fresh()->transfer;

        app(TransferBookingService::class)->refundMerchant(
            originalTransfer: $transfer,
            refundAmount: (int) $transfer->amount,
            initiatedBy: $sellerUser->id,
        );
        app(OrderService::class)->ripristinaScorteDopoRimborso($transfer);

        $this->assertSame(Order::STATUS_REFUNDED, $ordine->fresh()->status);
        $this->assertNotNull($ordine->fresh()->stock_restored_at);
        $this->assertSame(5, (int) $listing->fresh()->stock_quantity);
    }

    // =========================================================================
    // Quello che si vede in pagina
    // =========================================================================

    public function test_il_venditore_vede_il_bottone_annulla_solo_finche_puo_usarlo(): void
    {
        [$ordine, , , $sellerUser] = $this->ordineDaAnnullare();

        $this->actingAs($sellerUser)->get(route('portal.sales.show', $ordine))
            ->assertOk()
            ->assertSee('Annullare questo ordine');

        $ordine->forceFill(['status' => Order::STATUS_SHIPPED, 'shipped_at' => now()])->save();

        $this->actingAs($sellerUser)->get(route('portal.sales.show', $ordine))
            ->assertOk()
            ->assertDontSee('Annullare questo ordine');
    }

    public function test_il_compratore_vede_il_modulo_del_reso_solo_quando_serve(): void
    {
        [$ordine, $buyer] = $this->ordineConsegnato();

        $this->actingAs($buyer)->get(route('portal.orders.show', $ordine))
            ->assertOk()
            ->assertSee('Vuoi restituire questo ordine?');

        $this->chiediReso($ordine, $buyer);

        // Con una pratica aperta il modulo sparisce: si aspetta la risposta.
        $this->actingAs($buyer)->get(route('portal.orders.show', $ordine))
            ->assertOk()
            ->assertDontSee('Vuoi restituire questo ordine?');
    }

    public function test_il_venditore_trova_la_pratica_aperta_nella_pagina_e_nell_elenco(): void
    {
        [$ordine, $buyer, , $sellerUser] = $this->ordineConsegnato();
        $this->chiediReso($ordine, $buyer, 'Il pacco conteneva il prodotto sbagliato');

        $this->actingAs($sellerUser)->get(route('portal.sales.index'))
            ->assertOk()
            ->assertSee('Richiesta di reso in attesa di risposta');

        $this->actingAs($sellerUser)->get(route('portal.sales.show', $ordine))
            ->assertOk()
            ->assertSee('Il cliente ha chiesto un reso')
            ->assertSee('Il pacco conteneva il prodotto sbagliato');
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /**
     * Un ordine pagato per intero in KY, fermo prima della spedizione:
     * lo stato in cui l'annullamento è ancora possibile.
     *
     * @return array{0: Order, 1: User, 2: \App\Models\Account, 3: User, 4: \App\Models\Account, 5: \App\Models\Company, 6: Listing}
     */
    private function ordineDaAnnullare(): array
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser, $sellerAccount] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'title'          => 'Sedia impagliata',
            'delivery_type'  => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'stock_quantity' => 9,
        ]);

        $ordine = $this->ordina($buyerAccount, $buyer, $listing);
        $this->assertSame(Order::STATUS_PAID, $ordine->status);

        return [$ordine, $buyer, $buyerAccount, $sellerUser, $sellerAccount, $company, $listing];
    }

    /**
     * Lo stesso ordine, ma consegnato oggi: lo stato in cui il reso è
     * possibile e l'annullamento no.
     *
     * @return array{0: Order, 1: User, 2: \App\Models\Account, 3: User, 4: \App\Models\Account, 5: \App\Models\Company, 6: Listing}
     */
    private function ordineConsegnato(): array
    {
        $scenario = $this->ordineDaAnnullare();

        $scenario[0]->forceFill([
            'status'       => Order::STATUS_DELIVERED,
            'shipped_at'   => now()->subDays(3),
            'delivered_at' => now(),
        ])->save();

        $scenario[0] = $scenario[0]->fresh();

        return $scenario;
    }

    private function chiediReso(Order $ordine, User $buyer, string $motivo = 'Il prodotto è arrivato danneggiato'): OrderReturnRequest
    {
        return app(OrderService::class)->chiediReso($ordine, $buyer, $motivo);
    }

    /**
     * Azzera il conto del negozio come se avesse già speso l'incasso.
     *
     * Scritto a mano con una UPDATE e non con `forceFill(0)->save()`: il
     * modello che i test hanno in mano è quello di PRIMA della vendita, dove
     * il saldo era già zero. Eloquent scrive solo gli attributi cambiati,
     * quindi quel save() non avrebbe emesso nessuna query - e i test del fido
     * sarebbero passati per finta, con il conto ancora pieno.
     */
    private function svuotaIlConto(\App\Models\Account $conto): void
    {
        \App\Models\Account::query()->whereKey($conto->id)->update(['available_balance' => 0]);
    }

    private function ordina($buyerAccount, $buyer, $listing, int $quantita = 1): Order
    {
        return app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => $quantita]],
        );
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name'                => 'Admin del circuito',
            'email'               => 'admin-' . Str::random(8) . '@test.test',
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
}
