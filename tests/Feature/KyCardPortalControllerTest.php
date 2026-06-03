<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class KyCardPortalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyUser(): array
    {
        $slug = 'kycard-p-' . Str::random(4);

        $company = Company::create([
            'name'          => 'KyCard Portal Co',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'KyCard User',
            'email'               => 'kycard-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto KyCard',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $company];
    }

    public function test_ky_cards_index_requires_authentication(): void
    {
        $this->get(route('portal.ky-cards.index'))
            ->assertRedirect(route('login'));
    }

    public function test_company_user_can_view_ky_cards_index(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.ky-cards.index'))
            ->assertOk()
            ->assertSee('Ricarica', false);
    }

    public function test_company_user_can_view_ky_card_checkout(): void
    {
        [$user] = $this->makeCompanyUser();

        $card = KyCard::create([
            'name'            => 'Ricarica 100 KY',
            'ky_base_amount'  => 10000,
            'price_eur_cents' => 8500,
            'bonus_type'      => 'fixed',
            'bonus_value'     => 0,
            'is_active'       => true,
        ]);

        $this->actingAs($user)
            ->get(route('portal.ky-cards.checkout', $card))
            ->assertOk();
    }

    public function test_company_user_can_view_ky_card_history(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.ky-cards.storico'))
            ->assertOk();
    }
}
