<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FILTRO VENDITORE NELLO SHOP — /shop?company={id}
 *
 * I pulsanti "SHOP" della directory aziende (portal/companies.blade.php) e del
 * profilo azienda (portal/company-show.blade.php) linkavano gia'
 * /shop?company={id}, ma ListingController::index() IGNORAVA il parametro: chi
 * cliccava finiva sul catalogo intero del circuito invece che sui prodotti di
 * quel venditore (segnalato da Laura il 25/08/2026).
 *
 * Questi test fissano il comportamento: con `company` in query string la griglia
 * mostra SOLO i prodotti di quell'azienda, il filtro sopravvive alla ricerca e
 * alla paginazione, e senza il parametro il catalogo resta quello di prima.
 */
class ShopSellerFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_con_il_parametro_company_vede_solo_i_prodotti_di_quel_venditore(): void
    {
        $buyer = $this->makeBuyer();
        [$venditoreA] = $this->makeSeller('Alfa');
        [$venditoreB] = $this->makeSeller('Beta');

        $prodottoA = $this->makeListing($venditoreA, 'Torta di mele');
        $prodottoB = $this->makeListing($venditoreB, 'Chiave inglese');

        $response = $this->actingAs($buyer)
            ->get(route('portal.shop', ['company' => $venditoreA->id]))
            ->assertOk();

        $listings = $response->viewData('listings');

        $this->assertTrue($listings->contains('id', $prodottoA->id));
        $this->assertFalse($listings->contains('id', $prodottoB->id));
        $this->assertSame($venditoreA->id, $response->viewData('selectedCompany')?->id);
    }

    public function test_senza_il_parametro_company_lo_shop_resta_il_catalogo_di_tutto_il_circuito(): void
    {
        $buyer = $this->makeBuyer();
        [$venditoreA] = $this->makeSeller('Alfa');
        [$venditoreB] = $this->makeSeller('Beta');

        $prodottoA = $this->makeListing($venditoreA, 'Torta di mele');
        $prodottoB = $this->makeListing($venditoreB, 'Chiave inglese');

        $response = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk();
        $listings = $response->viewData('listings');

        $this->assertTrue($listings->contains('id', $prodottoA->id));
        $this->assertTrue($listings->contains('id', $prodottoB->id));
        $this->assertNull($response->viewData('selectedCompany'));
    }

    public function test_il_filtro_venditore_si_combina_con_la_ricerca_testuale(): void
    {
        $buyer = $this->makeBuyer();
        [$venditoreA] = $this->makeSeller('Alfa');
        [$venditoreB] = $this->makeSeller('Beta');

        $torta   = $this->makeListing($venditoreA, 'Torta di mele');
        $pane    = $this->makeListing($venditoreA, 'Pane fresco');
        $tortaB  = $this->makeListing($venditoreB, 'Torta salata');

        $response = $this->actingAs($buyer)
            ->get(route('portal.shop', ['company' => $venditoreA->id, 'q' => 'Torta']))
            ->assertOk();

        $listings = $response->viewData('listings');

        $this->assertTrue($listings->contains('id', $torta->id));
        $this->assertFalse($listings->contains('id', $pane->id), 'La ricerca deve restare attiva');
        $this->assertFalse($listings->contains('id', $tortaB->id), 'Il filtro venditore deve restare attivo');
    }

    /**
     * La fascia "in primo piano" pesca da tutto il circuito: dentro il negozio
     * di una singola azienda mostrerebbe prodotti di altri venditori, quindi
     * dev'essere vuota.
     */
    public function test_dentro_un_negozio_la_fascia_in_primo_piano_e_vuota(): void
    {
        $buyer = $this->makeBuyer();
        [$venditoreA] = $this->makeSeller('Alfa');
        [$venditoreB] = $this->makeSeller('Beta');

        $this->makeListing($venditoreA, 'Torta di mele');
        $this->makeListing($venditoreB, 'Chiave inglese', ['featured' => true]);

        $conFiltro = $this->actingAs($buyer)->get(route('portal.shop', ['company' => $venditoreA->id]))->assertOk();
        $this->assertTrue($conFiltro->viewData('featuredListings')->isEmpty());

        // Senza filtro la fascia continua a funzionare come prima.
        $senzaFiltro = $this->actingAs($buyer)->get(route('portal.shop'))->assertOk();
        $this->assertTrue($senzaFiltro->viewData('featuredListings')->isNotEmpty());
    }

    public function test_un_company_id_inesistente_non_rompe_la_pagina_e_mostra_il_catalogo(): void
    {
        $buyer = $this->makeBuyer();
        [$venditoreA] = $this->makeSeller('Alfa');
        $prodotto = $this->makeListing($venditoreA, 'Torta di mele');

        $response = $this->actingAs($buyer)
            ->get(route('portal.shop', ['company' => 999999]))
            ->assertOk();

        $this->assertNull($response->viewData('selectedCompany'));
        $this->assertTrue($response->viewData('listings')->contains('id', $prodotto->id));
    }

    public function test_la_pagina_del_negozio_mostra_il_banner_col_nome_del_venditore(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Fioravanti Fiori');
        $this->makeListing($venditore, 'Mazzo di rose');

        $this->actingAs($buyer)
            ->get(route('portal.shop', ['company' => $venditore->id]))
            ->assertOk()
            ->assertSee('Prodotti di '.$venditore->name)
            ->assertSee('Vedi tutto lo shop');
    }

    /**
     * Il filtro venditore deve sopravvivere a una ricerca o a un cambio di
     * categoria: la toolbar e' un form GET, quindi `company` va ripetuto come
     * campo nascosto — altrimenti al primo "Filtra" si tornava al catalogo
     * intero.
     */
    public function test_il_form_dei_filtri_conserva_il_venditore(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Fioravanti Fiori');
        $this->makeListing($venditore, 'Mazzo di rose');

        $this->actingAs($buyer)
            ->get(route('portal.shop', ['company' => $venditore->id]))
            ->assertOk()
            ->assertSee('name="company" value="'.$venditore->id.'"', false);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeBuyer(): User
    {
        $user = User::create([
            'name'                => 'Mario Rossi',
            'email'               => 'buyer-'.Str::random(8).'@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'private-owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        Account::create([
            'owner_user_id'     => $user->id,
            'owner_type'        => 'private',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 100000,
        ]);

        return $user->fresh();
    }

    /** @return array{0: Company, 1: User} */
    private function makeSeller(string $nome): array
    {
        $slug = Str::slug($nome).'-'.Str::random(6);

        $company = Company::create([
            'name'          => $nome,
            'slug'          => $slug,
            'email'         => $slug.'@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Azienda di test',
        ]);

        Account::create([
            'company_id'        => $company->id,
            'owner_type'        => 'company',
            'type'              => 'member',
            'status'            => 'active',
            'available_balance' => 0,
            'is_system_account' => false,
        ]);

        $user = User::create([
            'name'                => 'Titolare '.$nome,
            'email'               => 'owner-'.Str::random(8).'@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'role'                => 'owner',
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        return [$company->fresh(), $user->fresh()];
    }

    private function makeListing(Company $company, string $titolo, array $extra = []): Listing
    {
        return Listing::create(array_merge([
            'company_id'         => $company->id,
            'created_by_user_id' => User::query()->where('company_id', $company->id)->value('id'),
            'title'              => $titolo,
            'description'        => 'Descrizione di '.$titolo,
            'category'           => 'informatica',
            'price_ky'           => 5000,
            'ky_percentage'      => 100,
            'status'             => 'active',
            'delivery_type'      => Listing::DELIVERY_TYPE_SERVIZIO,
        ], $extra));
    }
}
