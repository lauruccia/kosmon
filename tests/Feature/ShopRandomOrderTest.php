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
 * ORDINE CASUALE NEL CATALOGO SHOP (05/10/2026, richiesta di Laura)
 *
 * /shop mescola i prodotti a ogni apertura. Il seme sta in sessione: pagina 1
 * lo rigenera, pagina 2+ lo riusa, cosi' sfogliando non ci sono doppioni ne'
 * prodotti persi. I "featured" restano in cima. Vale anche con ?company=ID.
 */
class ShopRandomOrderTest extends TestCase
{
    use RefreshDatabase;

    private function ids($response): array
    {
        return $response->viewData('listings')->pluck('id')->all();
    }

    public function test_le_pagine_successive_non_ripetono_ne_perdono_prodotti(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Alfa');
        for ($i = 1; $i <= 45; $i++) {
            $this->makeListing($venditore, "Prodotto $i");
        }

        $this->actingAs($buyer);
        $p1 = $this->ids($this->get(route('portal.shop'))->assertOk());
        $p2 = $this->ids($this->get(route('portal.shop', ['page' => 2]))->assertOk());
        $p3 = $this->ids($this->get(route('portal.shop', ['page' => 3]))->assertOk());

        $tutti = array_merge($p1, $p2, $p3);
        $this->assertCount(45, $tutti);
        $this->assertCount(45, array_unique($tutti));
    }

    public function test_riaprendo_la_pagina_1_l_ordine_cambia(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Alfa');
        for ($i = 1; $i <= 30; $i++) {
            $this->makeListing($venditore, "Prodotto $i");
        }

        $this->actingAs($buyer);
        $ordini = [];
        for ($k = 0; $k < 6; $k++) {
            $ordini[] = implode(',', $this->ids($this->get(route('portal.shop'))));
        }

        $this->assertGreaterThan(1, count(array_unique($ordini)), 'L\'ordine non cambia mai fra un caricamento e l\'altro.');
    }

    public function test_i_prodotti_in_primo_piano_restano_in_cima(): void
    {
        $buyer = $this->makeBuyer();
        [$venditore] = $this->makeSeller('Alfa');
        for ($i = 1; $i <= 25; $i++) {
            $this->makeListing($venditore, "Prodotto $i");
        }
        $vip = $this->makeListing($venditore, 'In evidenza', ['featured' => true]);

        $this->actingAs($buyer);
        for ($k = 0; $k < 4; $k++) {
            $this->assertSame($vip->id, $this->ids($this->get(route('portal.shop')))[0]);
        }
    }

    public function test_con_il_filtro_venditore_l_ordine_e_casuale_ma_solo_i_suoi_prodotti(): void
    {
        $buyer = $this->makeBuyer();
        [$a] = $this->makeSeller('Alfa');
        [$b] = $this->makeSeller('Beta');
        $mieiIds = [];
        for ($i = 1; $i <= 30; $i++) {
            $mieiIds[] = $this->makeListing($a, "A $i")->id;
            $this->makeListing($b, "B $i");
        }

        $this->actingAs($buyer);
        $ordini = [];
        for ($k = 0; $k < 6; $k++) {
            $ids = $this->ids($this->get(route('portal.shop', ['company' => $a->id]))->assertOk());
            $this->assertEmpty(array_diff($ids, $mieiIds));
            $ordini[] = implode(',', $ids);
        }
        $this->assertGreaterThan(1, count(array_unique($ordini)));
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
