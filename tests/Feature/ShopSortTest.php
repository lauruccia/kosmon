<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ORDINAMENTO ESPLICITO NELLO SHOP — /shop?sort=...
 *
 * Il predefinito resta "casuale" (ShopRandomOrderTest). Qui si fissa che ogni
 * ordinamento scelto dall'utente sia rispettato, con il prezzo EFFETTIVO
 * (offerta viva se c'e'), e che una chiave sconosciuta ricada sul casuale.
 */
class ShopSortTest extends TestCase
{
    use RefreshDatabase;

    private function titles(string $sort, array $extra = []): array
    {
        return $this->get(route('portal.shop', ['sort' => $sort] + $extra))
            ->assertOk()->viewData('listings')->pluck('title')->all();
    }

    private function seedCatalogo(): void
    {
        [$v] = $this->makeSeller('Alfa');
        $this->makeListing($v, 'banana', ['price_ky' => 3000, 'ky_percentage' => 50, 'created_at' => now()->subDays(3)]);
        $this->makeListing($v, 'Cedro',  ['price_ky' => 1000, 'ky_percentage' => 100, 'created_at' => now()->subDays(1)]);
        $this->makeListing($v, 'Arancia', ['price_ky' => 2000, 'ky_percentage' => 25, 'created_at' => now()->subDays(2)]);
    }

    public function test_ordina_per_prezzo(): void
    {
        $this->seedCatalogo();
        $this->actingAs($this->makeBuyer());
        $this->assertSame(['Cedro', 'Arancia', 'banana'], $this->titles('prezzo_asc'));
        $this->assertSame(['banana', 'Arancia', 'Cedro'], $this->titles('prezzo_desc'));
    }

    public function test_ordina_per_nome_senza_badare_alle_maiuscole(): void
    {
        $this->seedCatalogo();
        $this->actingAs($this->makeBuyer());
        $this->assertSame(['Arancia', 'banana', 'Cedro'], $this->titles('az'));
        $this->assertSame(['Cedro', 'banana', 'Arancia'], $this->titles('za'));
    }

    public function test_ordina_per_data(): void
    {
        $this->seedCatalogo();
        $this->actingAs($this->makeBuyer());
        $this->assertSame(['Cedro', 'Arancia', 'banana'], $this->titles('recenti'));
        $this->assertSame(['banana', 'Arancia', 'Cedro'], $this->titles('vecchi'));
    }

    public function test_ordina_per_percentuale_kmoney(): void
    {
        $this->seedCatalogo();
        $this->actingAs($this->makeBuyer());
        $this->assertSame(['Cedro', 'banana', 'Arancia'], $this->titles('ky_desc'));
        $this->assertSame(['Arancia', 'banana', 'Cedro'], $this->titles('ky_asc'));
    }

    public function test_il_prezzo_usato_e_quello_effettivo_con_l_offerta(): void
    {
        [$v, $owner] = $this->makeSeller('Alfa');
        $caro  = $this->makeListing($v, 'Caro', ['price_ky' => 9000]);
        $this->makeListing($v, 'Medio', ['price_ky' => 5000]);
        ListingOffer::create([
            'listing_id' => $caro->id, 'created_by_user_id' => $owner->id,
            'full_price_ky_snapshot' => 9000, 'offer_price_ky' => 1000, 'offer_ky_percentage' => 100,
            'expires_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->makeBuyer());
        // In offerta "Caro" costa 1000: e' il piu' economico e ha il maggior sconto.
        $this->assertSame(['Caro', 'Medio'], $this->titles('prezzo_asc'));
        $this->assertSame(['Caro', 'Medio'], $this->titles('sconto'));
    }

    public function test_un_ordinamento_esplicito_batte_i_prodotti_in_primo_piano(): void
    {
        [$v] = $this->makeSeller('Alfa');
        $this->makeListing($v, 'Zeta', ['featured' => true, 'price_ky' => 100]);
        $this->makeListing($v, 'Alfa', ['price_ky' => 200]);

        $this->actingAs($this->makeBuyer());
        $this->assertSame(['Alfa', 'Zeta'], $this->titles('az'));
    }

    public function test_una_chiave_sconosciuta_ricade_sul_casuale_senza_errori(): void
    {
        $this->seedCatalogo();
        $this->actingAs($this->makeBuyer());
        $this->assertCount(3, $this->titles('inventato'));
    }

    public function test_l_ordinamento_vale_anche_con_il_filtro_venditore_e_sopravvive_alla_paginazione(): void
    {
        [$a] = $this->makeSeller('Alfa');
        [$b] = $this->makeSeller('Beta');
        for ($i = 1; $i <= 25; $i++) {
            $this->makeListing($a, sprintf('A%02d', $i), ['price_ky' => $i * 100]);
            $this->makeListing($b, 'B'.$i, ['price_ky' => 1]);
        }

        $this->actingAs($this->makeBuyer());
        $p1 = $this->titles('prezzo_desc', ['company' => $a->id]);
        $p2 = $this->titles('prezzo_desc', ['company' => $a->id, 'page' => 2]);

        $this->assertSame('A25', $p1[0]);
        $this->assertCount(20, $p1);
        $this->assertSame(['A05', 'A04', 'A03', 'A02', 'A01'], $p2);
    }

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
        $creato = $extra['created_at'] ?? null;
        unset($extra['created_at']);

        $listing = Listing::create(array_merge([
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

        if ($creato !== null) {
            $listing->forceFill(['created_at' => $creato])->save();
        }

        return $listing;
    }
}
