<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\MarketplaceOrderPayment;
use App\Models\PaymentGateway;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\NewMarketplaceOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShopScenarios;
use Tests\TestCase;

/**
 * TEST DI REGRESSIONE — acquisto shop (ListingController::buy()).
 *
 * Scopo: fissare per iscritto il comportamento ATTUALE dell'acquisto, prima di
 * estrarre lo shop in un'applicazione separata (vedi PIANO_SHOP_ESTERNO.md).
 * Questi test non introducono comportamenti nuovi: descrivono quello che il
 * codice fa oggi, così che la nuova app possa essere confrontata contro lo
 * stesso identico metro, e così che una modifica futura che rompa uno di questi
 * comportamenti lo faccia sapere subito invece che in produzione.
 *
 * Prima di questo file l'acquisto shop — l'unico punto del portale in cui un
 * cliente muove KY comprando — non era coperto da alcun test.
 *
 * Importi: sempre in centesimi di KY (5000 = 50,00 KY), sotto il limite globale
 * per singolo trasferimento (200.000 = 2.000 KY, vedi
 * TransferBookingService::assertTransferWithinLimits()).
 */
class ShopPurchaseRegressionTest extends TestCase
{
    use BuildsShopScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // L'acquisto notifica il venditore (NewMarketplaceOrderNotification):
        // la intercettiamo per non dipendere da mail/web push nei test, e per
        // poter verificare che venga effettivamente inviata.
        Notification::fake();
    }

    // =========================================================================
    // 1. Il caso base: 100% KY
    // =========================================================================

    public function test_acquisto_100_ky_sposta_il_saldo_dal_compratore_al_venditore(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);          // 1.000,00 KY
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100); // 50,00 KY

        // Dal 26/08/2026 "Compra ora" finisce sulla pagina "grazie" col numero
        // d'ordine, non piu' su un banner verde sulla pagina prodotto: e' la
        // stessa uscita del carrello (audit, blocco 3).
        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertRedirectContains(route('portal.cart.thanks'));

        $this->assertSame(95000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(5000, $sellerAccount->fresh()->available_balance);

        // Nessun pagamento in euro: il prodotto è interamente in KY.
        $this->assertSame(0, MarketplaceOrderPayment::count());
    }

    public function test_acquisto_crea_un_movimento_con_kind_e_dati_ordine_corretti(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertSame($buyerAccount->id, $transfer->from_account_id);
        $this->assertSame($sellerAccount->id, $transfer->to_account_id);
        $this->assertSame(5000, (int) $transfer->amount);
        $this->assertSame('booked', $transfer->status);
        $this->assertSame($listing->id, (int) $transfer->listing_id);
        $this->assertSame(1, (int) $transfer->quantity);
        $this->assertNotNull($transfer->idempotency_key);
        $this->assertStringContainsString($listing->title, (string) $transfer->description);

        // Partita doppia: ogni movimento genera esattamente 2 righe di registro.
        $this->assertSame(2, $transfer->ledgerEntries()->count());
    }

    public function test_acquisto_lascia_traccia_in_audit_log_e_notifica_il_venditore(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company, $sellerUser] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', Transfer::class)
                ->where('auditable_id', $transfer->id)
                ->exists(),
            'Ogni acquisto deve lasciare una traccia in AuditLog.'
        );

        Notification::assertSentTo($sellerUser, NewMarketplaceOrderNotification::class);
    }

    // =========================================================================
    // 2. Mix KY/EUR
    // =========================================================================

    public function test_acquisto_con_mix_ky_eur_addebita_solo_la_quota_ky_e_crea_il_pagamento_in_euro(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $this->makeGateway($company);

        // 100,00 KY al 75% → 75,00 KY nel circuito + 25,00 EUR fuori circuito.
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 75);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $this->assertSame(92500, $buyerAccount->fresh()->available_balance);
        $this->assertSame(7500, $sellerAccount->fresh()->available_balance);

        $payment = MarketplaceOrderPayment::query()->sole();
        $this->assertSame(2500, $payment->amount);
        $this->assertSame(MarketplaceOrderPayment::STATUS_PENDING, $payment->status);
        $this->assertSame($company->id, $payment->company_id);
        $this->assertSame($listing->id, $payment->listing_id);
    }

    public function test_prodotto_con_quota_euro_e_bloccato_se_il_venditore_non_ha_un_metodo_di_pagamento(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        // Nessun PaymentGateway configurato per il venditore.
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 75);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error');

        // Il blocco deve avvenire PRIMA di qualsiasi addebito: niente KY mossi,
        // altrimenti il cliente resterebbe con la quota KY pagata e nessun modo
        // di saldare quella in euro.
        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
        $this->assertSame(0, MarketplaceOrderPayment::count());
    }

    public function test_gateway_attivo_ma_senza_credenziali_non_abilita_l_acquisto(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();

        // Gateway presente e attivo, ma con le credenziali vuote: non è usabile
        // (PaymentGateway::is_configured === false).
        PaymentGateway::create([
            'company_id'  => $company->id,
            'provider'    => PaymentGateway::PROVIDER_BANK_TRANSFER,
            'is_active'   => true,
            'credentials' => [],
        ]);

        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 75);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error');

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    // =========================================================================
    // 3. Quantità e spedizione
    // =========================================================================

    public function test_quantita_multipla_moltiplica_il_prodotto_ma_la_spedizione_si_paga_una_volta_sola(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);

        // 20,00 KY al 100%, spedizione 5,00 KY, prodotto fisico da spedire.
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
            'shipping_cost' => 500,
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 3]);

        // 3 x 20,00 + 5,00 di spedizione (UNA sola volta) = 65,00 KY
        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(6500, (int) $transfer->amount);
        $this->assertSame(3, (int) $transfer->quantity);

        $this->assertSame(93500, $buyerAccount->fresh()->available_balance);
        $this->assertSame(6500, $sellerAccount->fresh()->available_balance);
    }

    public function test_prodotto_da_spedire_salva_lo_snapshot_dell_indirizzo_sul_movimento(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        // L'indirizzo viene fotografato al momento dell'acquisto: se il cliente
        // lo cambia dopo, l'ordine già fatto resta storicamente corretto.
        $this->assertSame('Mario Rossi', $transfer->shipping_recipient_name);
        $this->assertSame('Via Roma 1', $transfer->shipping_address);
        $this->assertSame('Milano', $transfer->shipping_city);
        $this->assertSame('20100', $transfer->shipping_postal_code);

        $buyerAccount->forceFill(['shipping_city' => 'Torino'])->save();
        $this->assertSame('Milano', $transfer->fresh()->shipping_city);
    }

    public function test_prodotto_da_spedire_e_bloccato_se_manca_l_indirizzo_di_spedizione(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000, conIndirizzo: false);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_SPEDIZIONE,
        ]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error');

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    public function test_prodotto_a_ritiro_non_richiede_indirizzo_di_spedizione(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000, conIndirizzo: false);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'delivery_type' => Listing::DELIVERY_TYPE_RITIRO,
            'shipping_cost' => 500, // ignorato: non è un prodotto da spedire
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(2000, (int) $transfer->amount, 'La spedizione non va addebitata sui prodotti da ritirare.');
        $this->assertSame(98000, $buyerAccount->fresh()->available_balance);
    }

    // =========================================================================
    // 4. Offerta della settimana
    // =========================================================================

    public function test_offerta_attiva_addebita_il_prezzo_scontato_e_non_quello_di_listino(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company, , $sellerAccount] = $this->makeSeller(saldo: 0);
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 50);

        ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 10000,
            'offer_price_ky'         => 6000,   // 60,00 KY
            'offer_ky_percentage'    => 100,    // in offerta: tutto KY
            'expires_at'             => now()->addDays(3),
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(6000, (int) $transfer->amount);

        $this->assertSame(94000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(6000, $sellerAccount->fresh()->available_balance);

        // L'offerta è al 100% KY: nessuna quota in euro, anche se il prodotto
        // di listino sarebbe al 50%.
        $this->assertSame(0, MarketplaceOrderPayment::count());
    }

    public function test_offerta_scaduta_torna_al_prezzo_di_listino(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 10000, kyPercentage: 100);

        ListingOffer::create([
            'listing_id'             => $listing->id,
            'full_price_ky_snapshot' => 10000,
            'offer_price_ky'         => 6000,
            'offer_ky_percentage'    => 100,
            'expires_at'             => now()->subDay(), // scaduta ieri
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        $this->assertSame(10000, (int) $transfer->amount);
    }

    // =========================================================================
    // 5. Disponibilità (stock)
    // =========================================================================

    public function test_stock_insufficiente_non_addebita_nulla(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100, extra: [
            'stock_quantity' => 2,
        ]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 3])
            ->assertSessionHas('portal_error');

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(2, $listing->fresh()->stock_quantity);
        $this->assertSame(0, Transfer::count());
    }

    public function test_acquisto_riuscito_scala_lo_stock(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100, extra: [
            'stock_quantity' => 5,
        ]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 2]);

        $this->assertSame(3, $listing->fresh()->stock_quantity);
    }

    public function test_stock_illimitato_resta_illimitato_dopo_l_acquisto(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        // stock_quantity null = disponibilità illimitata (comportamento storico).
        $listing = $this->makeListing($company, prezzo: 2000, kyPercentage: 100);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1', 'quantity' => 4]);

        $this->assertNull($listing->fresh()->stock_quantity);
        $this->assertSame(8000, (int) Transfer::query()->where('kind', 'portal_marketplace_order')->sole()->amount);
    }

    // =========================================================================
    // 6. Blocchi commerciali
    // =========================================================================

    /**
     * NB: qui il messaggio esatto conta.
     *
     * Anche togliendo la guardia di ListingController::buy(), l'acquisto
     * resterebbe comunque bloccato più a valle dal motore finanziario ("Il
     * conto mittente e il conto destinatario devono essere diversi") — quindi
     * un test che si limitasse ad asserire "c'è un errore" passerebbe lo
     * stesso, cioè per il motivo sbagliato (verificato con una mutazione
     * deliberata del controller). Il valore della guardia è proprio dare un
     * messaggio comprensibile invece di uno tecnico: è quello che fissiamo.
     */
    public function test_non_si_puo_comprare_un_prodotto_della_propria_azienda(): void
    {
        [$company, $sellerUser, $sellerAccount] = $this->makeSeller(saldo: 100000);
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->actingAs($sellerUser)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertSessionHas('portal_error', 'Non puoi acquistare un prodotto pubblicato dalla tua stessa azienda.');

        $this->assertSame(100000, $sellerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    public function test_prodotto_sospeso_non_e_acquistabile(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100, extra: [
            'status' => 'suspended',
        ]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertRedirect(route('portal.shop'));

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    public function test_prodotto_scaduto_non_e_acquistabile(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100, extra: [
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($buyer)
            ->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertRedirect(route('portal.shop'));

        $this->assertSame(100000, $buyerAccount->fresh()->available_balance);
        $this->assertSame(0, Transfer::count());
    }

    public function test_acquisto_richiede_autenticazione(): void
    {
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, prezzo: 5000, kyPercentage: 100);

        $this->post(route('portal.shop.buy', $listing), ['accetto_condizioni' => '1'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Transfer::count());
    }
}
