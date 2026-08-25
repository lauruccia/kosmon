<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\Transfer;
use App\Services\OrderService;
use App\Services\TransferBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * FASE B — l'ordine diventa un'entità (PIANO_CARRELLO_VARIANTI.md).
 *
 * I test della fase A verificano che il comportamento visibile non sia
 * cambiato. Questi verificano che sotto sia comparso qualcosa: un ordine con
 * le sue righe, agganciato al movimento, con i prezzi congelati.
 *
 * Importi in CENTESIMI.
 */
class OrdersPhaseBTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1. Un acquisto normale adesso produce anche un ordine
    // =========================================================================

    public function test_l_acquisto_crea_un_ordine_con_la_sua_riga(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['quantity' => 2]);

        $order = Order::query()->sole();

        $this->assertSame($buyerAccount->id, (int) $order->buyer_account_id);
        $this->assertSame($buyer->id, (int) $order->buyer_user_id);
        $this->assertSame($company->id, (int) $order->company_id);
        $this->assertSame($sellerAccount->id, (int) $order->seller_account_id);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(10000, $order->total_ky);
        $this->assertSame(0, $order->total_eur);
        $this->assertNotNull($order->placed_at);
        $this->assertFalse($order->isBackfilled(), 'Un ordine appena fatto non è un ordine ricostruito.');

        $riga = $order->items()->sole();
        $this->assertSame($listing->id, (int) $riga->listing_id);
        $this->assertSame($listing->title, $riga->title);
        $this->assertSame(2, $riga->quantity);
        $this->assertSame(5000, $riga->unit_price_ky);
        $this->assertSame(100, $riga->ky_percentage);
        $this->assertSame(10000, $riga->line_ky_amount);
        $this->assertSame(0, $riga->line_eur_amount);
    }

    public function test_il_movimento_e_la_quota_euro_puntano_all_ordine(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 50);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $order    = Order::query()->sole();
        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $payment  = MarketplaceOrderPayment::query()->sole();

        $this->assertSame($order->id, (int) $transfer->order_id);
        $this->assertSame($order->id, (int) $payment->order_id);

        // E si naviga anche al contrario.
        $this->assertSame($transfer->id, $order->transfer->id);
        $this->assertSame($payment->id, $order->payment->id);

        // Con una quota in euro da saldare, l'ordine non è ancora "pagato".
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(2500, $order->total_eur);
        $this->assertTrue($order->hasEuroQuota());
    }

    public function test_la_riga_dell_ordine_non_cambia_se_il_prodotto_cambia_dopo(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $riga = Order::query()->sole()->items()->sole();
        $titoloOriginale = $riga->title;

        // Il venditore raddoppia il prezzo e rinomina il prodotto.
        $listing->forceFill(['price_ky' => 10000, 'title' => 'Nome completamente diverso'])->save();

        $riga->refresh();
        $this->assertSame($titoloOriginale, $riga->title, 'Il titolo dell\'ordine è uno snapshot, non un join.');
        $this->assertSame(5000, $riga->unit_price_ky, 'Il prezzo pagato non cambia se cambia il listino.');
    }

    public function test_la_riga_congela_il_prezzo_dell_offerta_non_quello_di_listino(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 50);

        // Offerta della settimana: prezzo scontato E mix diverso (tutto KY).
        \App\Models\ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 10000,
            'offer_price_ky'         => 6000,
            'offer_ky_percentage'    => 100,
            'expires_at'             => now()->addDays(3),
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $riga = Order::query()->sole()->items()->sole();

        // Quello che finisce nell'ordine è il prezzo davvero pagato, non il
        // listino: è la ragione per cui la riga è uno snapshot e non un join.
        $this->assertSame(6000, $riga->unit_price_ky);
        $this->assertSame(100, $riga->ky_percentage);
        $this->assertSame(6000, $riga->unit_ky_amount);
        $this->assertSame(0, $riga->unit_eur_amount);
        $this->assertSame(6000, Order::query()->sole()->total_ky);
    }

    public function test_un_ordine_sopravvive_alla_cancellazione_del_prodotto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $riga = Order::query()->sole()->items()->sole();
        $titolo = $riga->title;

        $listing->delete();

        $riga->refresh();
        $this->assertNull($riga->listing_id, 'Il collegamento al catalogo si stacca...');
        $this->assertSame($titolo, $riga->title, '...ma la riga resta leggibile.');
        $this->assertSame(1, Order::query()->count());
    }

    // =========================================================================
    // 2. Il servizio, chiamato direttamente: quello che servirà al carrello
    // =========================================================================

    public function test_piu_righe_dello_stesso_venditore_stanno_in_un_ordine_solo(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);
        $due = $this->makeListing($company, prezzo: 3000, kyPercentage: 100);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [
                ['listing' => $uno, 'quantity' => 2],   // 40,00
                ['listing' => $due, 'quantity' => 1],   // 30,00
            ],
        );

        $this->assertSame(7000, $order->total_ky);
        $this->assertCount(2, $order->items()->get());
        $this->assertSame(7000, $sellerAccount->fresh()->available_balance);
        $this->assertSame(93000, $buyerAccount->fresh()->available_balance);

        // Un ordine, un movimento solo: il venditore riceve un unico accredito.
        $this->assertSame(1, Transfer::where('kind', 'portal_marketplace_order')->count());
        $this->assertSame(3, (int) $order->transfer->quantity, 'Sul movimento la quantità è quella totale.');
    }

    public function test_la_spedizione_si_paga_una_volta_per_ordine_anche_con_piu_righe(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller(saldo: 0);

        $uno = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 500,
        ]);
        $due = $this->makeListing($company, prezzo: 3000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 300,
        ]);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [
                ['listing' => $uno, 'quantity' => 2],
                ['listing' => $due, 'quantity' => 1],
            ],
        );

        // 4000 + 3000 + UNA spedizione (la più cara: 500) = 7500
        $this->assertSame(500, $order->shipping_ky);
        $this->assertSame(7500, $order->total_ky);
    }

    public function test_un_ordine_con_due_venditori_viene_rifiutato(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$primaAzienda] = $this->makeSeller();
        [$secondaAzienda] = $this->makeSeller();

        $uno = $this->makeListing($primaAzienda, prezzo: 2000, kyPercentage: 100);
        $due = $this->makeListing($secondaAzienda, prezzo: 2000, kyPercentage: 100);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('un solo venditore');

        app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [
                ['listing' => $uno, 'quantity' => 1],
                ['listing' => $due, 'quantity' => 1],
            ],
        );
    }

    public function test_se_una_riga_e_esaurita_non_passa_nemmeno_l_altra(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);

        $disponibile = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 10]);
        $esaurito    = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 0]);

        try {
            app(OrderService::class)->place(
                buyerAccount: $buyerAccount,
                user: $buyer,
                righe: [
                    ['listing' => $disponibile, 'quantity' => 1],
                    ['listing' => $esaurito, 'quantity' => 1],
                ],
            );
            $this->fail('Doveva rifiutare l\'ordine.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('esaurito', strtolower($e->getMessage()));
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, $sellerAccount->fresh()->available_balance);
        $this->assertSame(10, (int) $disponibile->fresh()->stock_quantity, 'Nemmeno lo stock della riga buona si tocca.');
    }

    // =========================================================================
    // 3. Il backfill dello storico
    // =========================================================================

    public function test_il_backfill_ricostruisce_un_ordine_per_ogni_movimento_storico(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        // Un movimento "vecchio stile": nato prima che gli ordini esistessero,
        // quindi senza order_id. È esattamente quello che c'è in produzione.
        $vecchio = app(TransferBookingService::class)->book([
            'initiated_by'    => $buyer->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 6000,
            'kind'            => 'portal_marketplace_order',
            'description'     => 'Acquisto shop: roba vecchia (x3)',
            'listing_id'      => $listing->id,
            'quantity'        => 3,
            'order_title'     => 'Roba vecchia',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertNull($vecchio->order_id);

        // Si rifà girare la migrazione vera, andata e ritorno.
        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_25_150100_link_orders_to_transfers_and_payments.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_08_25_150100_link_orders_to_transfers_and_payments.php',
            '--force' => true,
        ]);

        $order = Order::query()->sole();

        $this->assertTrue($order->isBackfilled(), 'Un ordine ricostruito deve dichiararsi tale.');
        $this->assertSame($buyerAccount->id, (int) $order->buyer_account_id);
        $this->assertSame($company->id, (int) $order->company_id);
        $this->assertSame(6000, $order->total_ky);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame($order->id, (int) $vecchio->fresh()->order_id);

        $riga = $order->items()->sole();
        $this->assertSame('Roba vecchia', $riga->title, 'Il titolo viene dallo snapshot del movimento.');
        $this->assertSame(3, $riga->quantity);
        $this->assertSame(2000, $riga->unit_ky_amount, 'Il prezzo unitario è dedotto: 6000 / 3.');
        $this->assertSame(6000, $riga->line_ky_amount);
    }

    public function test_il_backfill_non_duplica_gli_ordini_gia_registrati(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        // Ordine nuovo, nato con il suo order_id già a posto.
        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));
        $this->assertSame(1, Order::query()->count());

        Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_08_25_150100_link_orders_to_transfers_and_payments.php',
            '--force' => true,
        ]);
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_08_25_150100_link_orders_to_transfers_and_payments.php',
            '--force' => true,
        ]);

        // Il rollback toglie la colonna di collegamento e con essa gli ordini,
        // che senza quel filo sarebbero orfani; il migrate successivo li
        // ricostruisce dai movimenti. Il risultato è UN ordine per acquisto,
        // non due: un ciclo rollback + migrate non deve moltiplicare niente.
        $this->assertSame(1, Order::query()->count(), 'Rollback e migrate non devono moltiplicare gli ordini.');
        $this->assertTrue(Order::query()->sole()->isBackfilled());
    }
}
