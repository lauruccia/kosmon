<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FASE 0b — snapshot dell'ordine sul movimento.
 *
 * Il punto di questi test è uno solo, e vale la pena dirlo chiaramente: un
 * movimento di acquisto deve restare LEGGIBILE anche quando il prodotto non
 * esiste più. Oggi il nome di cosa è stato comprato vive nella tabella
 * `listings`, che quando lo shop uscirà da qui (PIANO_SHOP_ESTERNO.md) non
 * esisterà più in questo database. Se non congeliamo il titolo sul movimento,
 * lo storico ordini diventa una lista di importi senza nome.
 *
 * Vedi anche ShopPurchaseRegressionTest, che fissa il comportamento
 * dell'acquisto nel suo insieme.
 */
class OrderSnapshotOnTransfersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    // =========================================================================
    // Lo snapshot viene scritto
    // =========================================================================

    public function test_acquisto_congela_titolo_e_provenienza_sul_movimento(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Tastiera meccanica');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertSame('Tastiera meccanica', $transfer->order_title);
        $this->assertSame(Transfer::ORDER_SOURCE_INTERNAL, $transfer->order_source);
        // Lo shop interno vende un prodotto per volta: non esiste un "ordine"
        // esterno da referenziare. Il campo serve a kshop.
        $this->assertNull($transfer->external_order_uuid);
        // La quantità NON è stata duplicata in una colonna nuova: resta quella
        // storica, `quantity` (vedi la migrazione 2026_08_24_140000).
        $this->assertSame(1, (int) $transfer->quantity);
    }

    public function test_snapshot_registra_la_quantita_ordinata_nella_colonna_storica(): void
    {
        [$buyer] = $this->makeBuyer(saldo: 100000);
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Cuffie', extra: ['stock_quantity' => 10]);

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing), ['quantity' => 3]);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertSame(3, (int) $transfer->quantity);
        $this->assertSame('Cuffie', $transfer->order_title);
    }

    // =========================================================================
    // Lo snapshot è storico: non insegue il catalogo
    // =========================================================================

    public function test_rinominare_il_prodotto_non_riscrive_gli_ordini_gia_fatti(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Monitor 24 pollici');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $listing->update(['title' => 'Monitor 27 pollici — nuovo modello']);

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        // Il cliente ha comprato il 24 pollici: sulla sua ricevuta deve restare
        // scritto quello, non il prodotto in cui il venditore l'ha trasformato.
        $this->assertSame('Monitor 24 pollici', $transfer->order_title);
        $this->assertSame('Monitor 24 pollici', $transfer->order_label);
        $this->assertSame('Monitor 27 pollici — nuovo modello', $transfer->listing->title);
    }

    public function test_movimento_resta_leggibile_quando_il_prodotto_sparisce(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Corso di fotografia');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $listing->delete();

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole()->fresh();

        // È esattamente lo scenario del giorno in cui il catalogo uscirà di qui.
        $this->assertNull($transfer->listing, 'Il prodotto non esiste più a catalogo.');
        $this->assertSame('Corso di fotografia', $transfer->order_label);
    }

    // =========================================================================
    // Fallback per lo storico anteriore alla migrazione
    // =========================================================================

    public function test_movimento_senza_snapshot_ricade_sul_titolo_del_prodotto(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Ordine vecchio');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();
        // Simula un movimento registrato PRIMA della migrazione 0b: nessuno
        // snapshot, solo la FK. Il backfill copre i movimenti già in tabella,
        // ma la rete di sicurezza deve esserci comunque.
        $transfer->forceFill(['order_title' => null, 'order_source' => null])->save();

        $this->assertSame('Ordine vecchio', $transfer->fresh()->order_label);
    }

    public function test_la_migrazione_riempie_lo_storico_gia_in_tabella(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Poltrona in pelle');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));

        // Torna indietro allo schema PRIMA della fase 0b (le tre colonne non
        // esistono più: l'ordine appena fatto è ora, a tutti gli effetti, un
        // movimento storico) e poi rifà la migrazione vera — backfill incluso.
        // Non è una simulazione: gira lo stesso file che andrà in produzione.
        $path = 'database/migrations/2026_08_24_140000_add_order_snapshot_to_transfers_table.php';

        $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
        $this->artisan('migrate', ['--path' => $path])->assertSuccessful();

        $transfer = Transfer::query()->where('kind', 'portal_marketplace_order')->sole();

        $this->assertSame('Poltrona in pelle', $transfer->order_title);
        $this->assertSame(Transfer::ORDER_SOURCE_INTERNAL, $transfer->order_source);
    }

    public function test_order_label_e_null_solo_se_manca_sia_snapshot_sia_prodotto(): void
    {
        $transfer = new Transfer(['order_title' => null]);

        $this->assertNull($transfer->order_label);
    }

    // =========================================================================
    // Il backoffice legge lo snapshot, non il catalogo
    // =========================================================================

    public function test_lista_ordini_admin_mostra_il_titolo_anche_senza_prodotto(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Bicicletta pieghevole');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));
        $listing->delete();

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.listings.orders'))
            ->assertOk()
            ->assertSee('Bicicletta pieghevole')
            ->assertDontSee('Prodotto rimosso');
    }

    public function test_ricerca_ordini_admin_trova_per_titolo_congelato(): void
    {
        [$buyer] = $this->makeBuyer();
        [$company] = $this->makeSeller();
        $listing = $this->makeListing($company, 'Zaino da trekking');

        $this->actingAs($buyer)->post(route('portal.shop.buy', $listing));
        $listing->delete();

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.listings.orders', ['q' => 'trekking']))
            ->assertOk()
            ->assertSee('Zaino da trekking');
    }

    // =========================================================================
    // Il formato che userà kshop
    // =========================================================================

    public function test_ordine_esterno_senza_prodotto_a_catalogo_e_leggibile(): void
    {
        [$buyer, $buyerAccount] = $this->makeBuyer();
        [, , $sellerAccount]    = $this->makeSeller();

        $uuid = (string) Str::uuid();

        $transfer = app(\App\Services\TransferBookingService::class)->book([
            'initiated_by'        => $buyer->id,
            'from_account_id'     => $buyerAccount->id,
            'to_account_id'       => $sellerAccount->id,
            'amount'              => 5000,
            'kind'                => 'portal_marketplace_order',
            'description'         => 'Ordine Kosmoshop',
            // Nessun listing_id: il catalogo non è più qui. È tutto quello che
            // kshop potrà passare, ed è tutto quello che serve.
            'external_order_uuid' => $uuid,
            'order_title'         => 'Scarpe modello X — taglia 42, nero',
            'order_source'        => Transfer::ORDER_SOURCE_KSHOP,
            'quantity'            => 2,
            'idempotency_key'     => (string) Str::uuid(),
        ]);

        $transfer = $transfer->fresh();

        $this->assertNull($transfer->listing_id);
        $this->assertSame($uuid, $transfer->external_order_uuid);
        $this->assertSame(Transfer::ORDER_SOURCE_KSHOP, $transfer->order_source);
        $this->assertSame('Scarpe modello X — taglia 42, nero', $transfer->order_label);
        // Il movimento è un movimento normale: partita doppia e saldi identici.
        $this->assertSame(2, $transfer->ledgerEntries()->count());
        $this->assertSame(95000, $buyerAccount->fresh()->available_balance);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /** @return array{0: User, 1: Account} */
    private function makeBuyer(int $saldo = 100000): array
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'buyer-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => $saldo,
        ]);

        return [$user->fresh(), $account->fresh()];
    }

    /** @return array{0: Company, 1: User, 2: Account} */
    private function makeSeller(): array
    {
        $slug = 'seller-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Venditore Test ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
            'is_system_account' => false,
        ]);

        $user = User::create([
            'name'                => 'Titolare ' . $company->name,
            'email'               => 'owner-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account->forceFill(['owner_user_id' => $user->id])->save();

        return [$company->fresh(), $user->fresh(), $account->fresh()];
    }

    private function makeListing(Company $company, string $titolo, array $extra = []): Listing
    {
        return Listing::create(array_merge([
            'company_id'         => $company->id,
            'created_by_user_id' => User::query()->where('company_id', $company->id)->value('id'),
            'title'              => $titolo,
            'description'        => 'Descrizione del prodotto di prova.',
            'category'           => 'informatica',
            'price_ky'           => 5000,
            'ky_percentage'      => 100,
            'status'             => 'active',
            'delivery_type'      => Listing::DELIVERY_TYPE_SERVIZIO,
        ], $extra));
    }

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin Ordini',
            'email'               => 'admin-ord-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
