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
 * "PRODOTTI CORRELATI" = ALTRI PRODOTTI DELLO STESSO VENDITORE
 *
 * Fino al 07/09/2026 la fascia in fondo alla scheda prodotto pescava per
 * CATEGORIA da tutto il circuito: sotto un prodotto comparivano prodotti simili
 * di altri venditori. La scheda mandava via il compratore invece di
 * trattenerlo, e chi pubblica nello shop si trovava la vetrina dei concorrenti
 * stampata sotto il proprio prodotto (segnalato da Laura l'08/09/2026).
 *
 * Da oggi la fascia resta dentro il negozio: stesso `company_id`, con la stessa
 * categoria ordinata prima. E quando il venditore non ha altri prodotti la
 * sezione sparisce — nessun ripiego sul catalogo generale, che rimetterebbe in
 * pagina esattamente cio' che questa modifica toglie.
 */
class ShopCorrelatiVenditoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_i_correlati_sono_solo_prodotti_dello_stesso_venditore(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Fioravanti Fiori');
        [$rivale]    = $this->makeSeller('Fiori del Sud');

        $prodotto = $this->makeListing($venditore, 'Mazzo di rose');
        $suo      = $this->makeListing($venditore, 'Bouquet di tulipani');
        $altrui   = $this->makeListing($rivale, 'Mazzo di margherite');

        $related = $this->actingAs($buyer)
            ->get(route('portal.shop.show', $prodotto))
            ->assertOk()
            ->viewData('related');

        $this->assertTrue($related->contains('id', $suo->id));
        $this->assertFalse(
            $related->contains('id', $altrui->id),
            'Sotto un prodotto non deve comparire il prodotto di un concorrente.'
        );
        $this->assertFalse($related->contains('id', $prodotto->id), 'Il prodotto stesso non e\' un suo correlato.');
    }

    /**
     * La categoria non FILTRA, ORDINA. Un venditore con quattro categorie e un
     * prodotto per categoria avrebbe la fascia vuota proprio quando avrebbe
     * piu' da mostrare: quindi ci sono tutti, ma i piu' pertinenti per primi.
     */
    public function test_la_stessa_categoria_viene_prima_ma_le_altre_restano(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Bottega Mista');

        $prodotto     = $this->makeListing($venditore, 'Trapano', ['category' => 'informatica']);
        $altraCat     = $this->makeListing($venditore, 'Maglietta', ['category' => 'abbigliamento']);
        $stessaCat    = $this->makeListing($venditore, 'Avvitatore', ['category' => 'informatica']);

        $related = $this->actingAs($buyer)
            ->get(route('portal.shop.show', $prodotto))
            ->assertOk()
            ->viewData('related');

        $this->assertSame(
            $stessaCat->id,
            $related->first()->id,
            'Chi guarda un utensile vuole vedere prima gli altri utensili.'
        );
        $this->assertTrue($related->contains('id', $altraCat->id), 'Le altre categorie del venditore restano, in coda.');
    }

    public function test_se_il_venditore_non_ha_altri_prodotti_la_sezione_non_compare(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Solo Un Prodotto');
        [$rivale]    = $this->makeSeller('Fiori del Sud');

        $prodotto = $this->makeListing($venditore, 'Mazzo di rose');
        $this->makeListing($rivale, 'Mazzo di margherite');

        $response = $this->actingAs($buyer)->get(route('portal.shop.show', $prodotto))->assertOk();

        $this->assertTrue(
            $response->viewData('related')->isEmpty(),
            'Niente ripiego sul catalogo generale: meglio nessuna fascia che la fascia dei concorrenti.'
        );
        $response->assertDontSee('Altri prodotti di');
    }

    /**
     * Un prodotto sospeso non ha una pagina raggiungibile (show() la blocca per
     * chiunque, proprietario incluso): finirebbe in un link verso un redirect
     * "non disponibile".
     */
    public function test_i_prodotti_sospesi_del_venditore_non_finiscono_nei_correlati(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Fioravanti Fiori');

        $prodotto = $this->makeListing($venditore, 'Mazzo di rose');
        $sospeso  = $this->makeListing($venditore, 'Orchidea', ['status' => 'suspended']);
        $attivo   = $this->makeListing($venditore, 'Bouquet di tulipani');

        $related = $this->actingAs($buyer)
            ->get(route('portal.shop.show', $prodotto))
            ->assertOk()
            ->viewData('related');

        $this->assertTrue($related->contains('id', $attivo->id));
        $this->assertFalse($related->contains('id', $sospeso->id));
    }

    /**
     * Nella scheda prodotto il nome del venditore porta ai SUOI prodotti — chi
     * legge quel nome sotto un prodotto si sta chiedendo cos'altro vende, non
     * che partita IVA ha. La scheda azienda, che fino a ieri stava proprio su
     * quel nome, resta raggiungibile dal link piccolo nella colonna a destra.
     */
    public function test_nella_scheda_prodotto_il_venditore_porta_ai_suoi_prodotti_e_al_profilo(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Fioravanti Fiori');
        $prodotto = $this->makeListing($venditore, 'Mazzo di rose');

        $this->actingAs($buyer)
            ->get(route('portal.shop.show', $prodotto))
            ->assertOk()
            ->assertSee('href="'.e(route('portal.shop', ['company' => $venditore->id])).'"', false)
            ->assertSee('href="'.e(route('portal.companies.show', $venditore->slug)).'"', false)
            ->assertSee('Scheda azienda');
    }

    // ── Helper (stessi di ShopSellerFilterTest) ───────────────────────────────

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
