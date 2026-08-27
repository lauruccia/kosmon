<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketplaceOrderPayment;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\Transfer;
use App\Models\User;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\TransferBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * BLOCCO 1 dell'audit del 26/08/2026 — le cinque cose che toccano i soldi.
 *
 * Ognuno di questi test difende una correzione precisa, e quasi tutti
 * verificano la stessa cosa in fondo: **che quando qualcosa va storto, i KY
 * non si siano mossi**. È il motivo per cui in quasi ogni test compare
 * `available_balance`: non basta che l'operazione fallisca, deve fallire
 * lasciando il conto com'era.
 *
 * Riferimento: AUDIT_ECOMMERCE_2026-08-26.md, blocco 1.
 *
 * Importi in CENTESIMI.
 */
class AuditBlocco1Test extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    // =========================================================================
    // 1.1 — Doppio addebito con due richieste concorrenti alla cassa
    // =========================================================================

    /**
     * La simulazione della corsa.
     *
     * `checkout()` legge il carrello, ne carica le righe, fa i suoi controlli e
     * solo dopo apre la transazione. La finestra pericolosa è fra la lettura e
     * la transazione: lì dentro può essersi infilata una richiesta gemella che
     * ha già trasformato il carrello in ordine.
     *
     * Il gancio su `CartItem::retrieved` riproduce esattamente quella finestra:
     * scatta durante il `load()` delle righe — cioè dopo che il carrello è
     * stato letto come `active` — e porta la riga a `ordered` con una query
     * grezza, come avrebbe fatto l'altra richiesta committando.
     *
     * Senza il lock dentro la transazione, da qui in poi si arrivava a pagare.
     */
    public function test_una_richiesta_gemella_non_paga_un_carrello_gia_diventato_ordine(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 1]);

        $cartId = Cart::attivoPer($buyerAccount)->id;
        $saldoPrima = (int) $buyerAccount->fresh()->available_balance;

        $gia = false;
        CartItem::retrieved(function () use ($cartId, &$gia): void {
            if ($gia) {
                return;
            }
            $gia = true;
            DB::table('carts')->where('id', $cartId)->update(['status' => Cart::STATUS_ORDERED]);
        });

        try {
            app(CartService::class)->checkout($buyerAccount, $buyer, '127.0.0.1');
            $this->fail('La richiesta gemella doveva essere fermata.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('già stato trasformato in ordine', $e->getMessage());
        }

        // Il punto di tutto il test: nessun secondo addebito.
        $this->assertSame($saldoPrima, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count(), 'Non deve nascere un secondo ordine.');
        $this->assertSame(0, Transfer::where('kind', 'portal_marketplace_order')->count());
    }

    public function test_la_cassa_normale_continua_a_funzionare(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.cart.add', $listing), ['quantity' => 2]);

        $ordini = app(CartService::class)->checkout($buyerAccount, $buyer, '127.0.0.1');

        $this->assertCount(1, $ordini);
        $this->assertSame(96000, (int) $buyerAccount->fresh()->available_balance);

        // Il carrello di prima è chiuso, e quello "attivo" adesso è nuovo e vuoto.
        $this->assertSame(1, Cart::where('status', Cart::STATUS_ORDERED)->count());
        $this->assertTrue(Cart::attivoPer($buyerAccount->fresh())->isVuoto());
    }

    // =========================================================================
    // 1.2 — Lo stato dell'ordine segue gli euro
    // =========================================================================

    public function test_quando_la_quota_in_euro_risulta_incassata_l_ordine_diventa_pagato(): void
    {
        [$order, $payment] = $this->ordineConQuotaEuro();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $payment->update([
            'status'  => MarketplaceOrderPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_la_conferma_del_bonifico_dal_venditore_porta_l_ordine_a_pagato(): void
    {
        [$order, $payment, $sellerUser] = $this->ordineConQuotaEuro();

        $payment->update(['provider' => PaymentGateway::PROVIDER_BANK_TRANSFER]);

        $this->actingAs($sellerUser)
            ->post(route('portal.shop.orders.confirm-bank', $payment))
            ->assertSessionHas('portal_success');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_un_passaggio_di_stato_che_non_e_pagato_non_tocca_l_ordine(): void
    {
        [$order, $payment] = $this->ordineConQuotaEuro();

        $payment->update(['status' => MarketplaceOrderPayment::STATUS_AWAITING_CONFIRMATION]);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_un_ordine_gia_rimborsato_non_torna_pagato(): void
    {
        [$order, $payment] = $this->ordineConQuotaEuro();

        $order->forceFill(['status' => Order::STATUS_REFUNDED])->save();

        $payment->update([
            'status'  => MarketplaceOrderPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status,
            'Un rimborso non deve poter essere annullato dall\'incasso della quota euro.');
    }

    // =========================================================================
    // 1.3 — Il rimborso totale rimette la merce in magazzino
    // =========================================================================

    public function test_il_rimborso_totale_rimette_i_pezzi_in_magazzino_e_marca_l_ordine(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 10]);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 3]],
        );

        $this->assertSame(7, (int) $listing->fresh()->stock_quantity, 'Le scorte devono essere scalate all\'acquisto.');

        $this->rimborsaPerIntero($order, $sellerUser);

        app(OrderService::class)->ripristinaScorteDopoRimborso($order->fresh()->transfer);

        $this->assertSame(10, (int) $listing->fresh()->stock_quantity, 'I 3 pezzi devono tornare disponibili.');
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_i_pezzi_tornano_sulla_combinazione_non_sul_prodotto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 50]);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $variante = $this->makeVariante($listing, [$taglie['M']], scorte: 4);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => $variante, 'quantity' => 2]],
        );

        $this->assertSame(2, (int) $variante->fresh()->stock_quantity);
        $this->assertSame(50, (int) $listing->fresh()->stock_quantity, 'Il prodotto padre non si tocca mai.');

        $this->rimborsaPerIntero($order, $sellerUser);
        app(OrderService::class)->ripristinaScorteDopoRimborso($order->fresh()->transfer);

        $this->assertSame(4, (int) $variante->fresh()->stock_quantity);
        $this->assertSame(50, (int) $listing->fresh()->stock_quantity,
            'Rimettere i pezzi sul prodotto invece che sulla taglia gonfierebbe il magazzino.');
    }

    public function test_una_combinazione_cancellata_non_gonfia_il_magazzino_del_prodotto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 50]);
        $taglie = $this->makeAttributo('Taglia', ['S', 'M']);
        $variante = $this->makeVariante($listing, [$taglie['M']], scorte: 4);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => $variante, 'quantity' => 2]],
        );

        // Il venditore cancella la taglia: `listing_variant_id` diventa NULL
        // sulla riga d'ordine, ma `variant_label` resta come snapshot.
        ListingVariant::find($variante->id)->delete();
        $riga = $order->fresh()->items()->sole();
        $this->assertNull($riga->listing_variant_id);
        $this->assertNotNull($riga->variant_label);

        $this->rimborsaPerIntero($order, $sellerUser);
        app(OrderService::class)->ripristinaScorteDopoRimborso($order->fresh()->transfer);

        $this->assertSame(50, (int) $listing->fresh()->stock_quantity,
            'I pezzi erano stati tolti a una taglia che non esiste più: non vanno rimessi sul prodotto.');
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_un_rimborso_parziale_non_tocca_ne_le_scorte_ne_l_ordine(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 10]);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 3]],
        );

        $transfer = $order->fresh()->transfer;

        app(TransferBookingService::class)->refundMerchant(
            originalTransfer: $transfer,
            refundAmount: (int) ($transfer->amount / 2),
            initiatedBy: $sellerUser->id,
        );

        app(OrderService::class)->ripristinaScorteDopoRimborso($transfer);

        $this->assertSame(7, (int) $listing->fresh()->stock_quantity,
            'Su un rimborso parziale non si può sapere quanti pezzi siano tornati.');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_ripristinare_due_volte_non_raddoppia_le_scorte(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 10]);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 3]],
        );

        $this->rimborsaPerIntero($order, $sellerUser);
        $transfer = $order->fresh()->transfer;

        app(OrderService::class)->ripristinaScorteDopoRimborso($transfer);
        app(OrderService::class)->ripristinaScorteDopoRimborso($transfer);

        $this->assertSame(10, (int) $listing->fresh()->stock_quantity);
    }

    public function test_il_rimborso_dal_portale_ripristina_le_scorte_da_solo(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: ['stock_quantity' => 10]);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 3]],
        );

        $transfer = $order->fresh()->transfer;

        $this->actingAs($sellerUser)
            ->post(route('portal.refund.submit', $transfer), [
                'amount' => ky_format((int) $transfer->amount),
            ])
            ->assertSessionHas('portal_success');

        $this->assertSame(10, (int) $listing->fresh()->stock_quantity,
            'Il venditore non deve doversi ricordare di correggere il magazzino a mano.');
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_un_movimento_che_non_e_un_ordine_dello_shop_non_rompe_niente(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [, , $sellerAccount] = $this->makeSeller();

        $transfer = app(TransferBookingService::class)->book([
            'initiated_by'    => $buyer->id,
            'from_account_id' => $buyerAccount->id,
            'to_account_id'   => $sellerAccount->id,
            'amount'          => 5000,
            'kind'            => 'portal_payment',
            'description'     => 'Pagamento normale',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertNull(app(OrderService::class)->ripristinaScorteDopoRimborso($transfer));
    }

    // =========================================================================
    // 1.4 — La quota in euro dev'essere incassabile
    // =========================================================================

    public function test_una_quota_in_euro_sotto_il_minimo_ferma_l_acquisto_prima_di_toccare_i_ky(): void
    {
        config(['kmoney.shop.min_euro_quota' => 50]);

        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);

        // 1,00 KY al 75% -> 75 di KY e 25 centesimi di euro: sotto il minimo.
        $listing = $this->makeListing($company, prezzo: 100, kyPercentage: 75);

        try {
            app(OrderService::class)->place(
                buyerAccount: $buyerAccount,
                user: $buyer,
                righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
            );
            $this->fail('L\'acquisto doveva essere bloccato.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('troppo bassa per essere incassata', $e->getMessage());
        }

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance,
            'I KY non devono uscire per un ordine che poi resterebbe bloccato.');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, MarketplaceOrderPayment::count());
    }

    public function test_una_quota_in_euro_esattamente_al_minimo_passa(): void
    {
        config(['kmoney.shop.min_euro_quota' => 50]);

        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);

        // 2,00 KY al 75% -> 150 di KY e 50 centesimi di euro: esattamente il minimo.
        $listing = $this->makeListing($company, prezzo: 200, kyPercentage: 75);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );

        $this->assertSame(50, (int) $order->total_eur);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
    }

    public function test_aumentare_la_quantita_sblocca_l_acquisto(): void
    {
        config(['kmoney.shop.min_euro_quota' => 50]);

        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);
        $listing = $this->makeListing($company, prezzo: 100, kyPercentage: 75);

        // Il consiglio che il messaggio d'errore dà all'utente deve funzionare
        // davvero: due pezzi fanno 50 centesimi di quota euro.
        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 2]],
        );

        $this->assertSame(50, (int) $order->total_eur);
    }

    public function test_un_ordine_tutto_in_ky_non_e_toccato_dal_minimo(): void
    {
        config(['kmoney.shop.min_euro_quota' => 50]);

        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 100, kyPercentage: 100);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );

        $this->assertSame(0, (int) $order->total_eur);
        $this->assertSame(Order::STATUS_PAID, $order->status);
    }

    // =========================================================================
    // 1.5 — Prezzo minimo del prodotto
    // =========================================================================

    public function test_il_form_prodotto_rifiuta_un_prezzo_sotto_il_minimo(): void
    {
        config(['kmoney.shop.min_price_ky' => 100]);

        [, $sellerUser] = $this->makeSeller();
        $this->makeCategoria();

        // '0.99' e non '0,01': il valore dev'essere un numero valido, altrimenti
        // a farlo cadere sarebbe la regola `numeric` e questo test passerebbe
        // senza aver mai messo alla prova la soglia.
        $this->actingAs($sellerUser)
            ->post(route('portal.shop.store'), $this->datiProdotto(['price_ky' => '0.99']))
            ->assertSessionHasErrors('price_ky');

        $this->assertSame(0, Listing::count());
    }

    public function test_il_form_prodotto_accetta_il_prezzo_minimo(): void
    {
        config(['kmoney.shop.min_price_ky' => 100]);

        [, $sellerUser] = $this->makeSeller();
        $this->makeCategoria();

        $this->actingAs($sellerUser)
            ->post(route('portal.shop.store'), $this->datiProdotto(['price_ky' => '1.00']))
            ->assertSessionHasNoErrors();

        $this->assertSame(100, (int) Listing::sole()->price_ky);
    }

    /**
     * Difesa in profondità: i prodotti caricati PRIMA della soglia sono ancora
     * a database, e nessuno li ha corretti. Devono fallire con un messaggio che
     * dice quale prodotto è, non con un errore muto che porta giù tutto il
     * carrello.
     */
    public function test_un_prodotto_vecchio_sotto_soglia_fallisce_dicendo_quale_e(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $this->makeGateway($company);

        // Scritto direttamente a database, come un prodotto di prima della regola.
        $listing = $this->makeListing($company, prezzo: 1, kyPercentage: 25, extra: ['title' => 'Bottone sfuso']);

        try {
            app(OrderService::class)->place(
                buyerAccount: $buyerAccount,
                user: $buyer,
                righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
            );
            $this->fail('Un prodotto con quota KY nulla non deve essere acquistabile.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Bottone sfuso', $e->getMessage(),
                'Il messaggio deve dire QUALE prodotto blocca l\'acquisto.');
        }

        $this->assertSame(100000, (int) $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Order::count());
    }

    // =========================================================================
    // Impalcatura
    // =========================================================================

    /**
     * Un ordine con una quota in euro ancora da saldare.
     *
     * @return array{0: Order, 1: MarketplaceOrderPayment, 2: User}
     */
    private function ordineConQuotaEuro(): array
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $this->makeGateway($company);

        // 20,00 KY al 75% -> 15,00 KY e 5,00 EUR.
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 75);

        $order = app(OrderService::class)->place(
            buyerAccount: $buyerAccount,
            user: $buyer,
            righe: [['listing' => $listing, 'variant' => null, 'quantity' => 1]],
        );

        return [$order, $order->fresh()->payment, $sellerUser];
    }

    private function rimborsaPerIntero(Order $order, User $sellerUser): Transfer
    {
        $transfer = $order->fresh()->transfer;

        return app(TransferBookingService::class)->refundMerchant(
            originalTransfer: $transfer,
            refundAmount: (int) $transfer->amount,
            initiatedBy: $sellerUser->id,
        );
    }

    /**
     * Le categorie dello shop vivono in `listing_categories` dal 12/08/2026 e
     * il form le valida contro quella tabella: senza una categoria attiva
     * nessun prodotto e' salvabile, nemmeno a prezzo giusto.
     */
    private function makeCategoria(string $slug = 'informatica'): \App\Models\ListingCategory
    {
        return \App\Models\ListingCategory::create([
            'parent_id' => null,
            'slug'      => $slug,
            'name'      => 'Informatica',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function datiProdotto(array $override = []): array
    {
        return array_merge([
            'title'         => 'Prodotto nuovo',
            'description'   => 'Descrizione sufficientemente lunga.',
            'category'      => 'informatica',
            'price_ky'      => '10.00',
            'ky_percentage' => 100,
            'stock_mode'    => 'unlimited',
            'delivery_type' => Listing::DELIVERY_TYPE_SERVIZIO,
        ], $override);
    }
}
