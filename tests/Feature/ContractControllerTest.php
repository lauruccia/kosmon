<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un utente aziendale con KYC approvato ma contratto non firmato.
     */
    private function makeUnsignedCompanyUser(): array
    {
        $slug = 'contract-' . Str::random(4);

        $company = Company::create([
            'name'          => 'Contract Co',
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
            'name'                => 'Contract User',
            'email'               => 'contract-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        // contract_signed_at NON impostato: l'utente deve firmare

        Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Contract',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $company];
    }

    public function test_contract_page_requires_authentication(): void
    {
        $this->get(route('portal.contract.sign'))
            ->assertRedirect(route('login'));
    }

    public function test_unsigned_user_can_view_contract_page(): void
    {
        [$user] = $this->makeUnsignedCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.contract.sign'))
            ->assertOk()
            ->assertSee('contratto', false);
    }

    public function test_already_signed_user_is_redirected_from_contract_page(): void
    {
        [$user] = $this->makeUnsignedCompanyUser();
        $user->forceFill(['contract_signed_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('portal.contract.sign'))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_signed_user_can_view_their_contract(): void
    {
        [$user] = $this->makeUnsignedCompanyUser();
        $user->forceFill(['contract_signed_at' => now()])->save();

        $this->actingAs($user)
            ->get(route('portal.contract.view'))
            ->assertOk();
    }

    public function test_unsigned_user_is_redirected_from_view_contract(): void
    {
        [$user] = $this->makeUnsignedCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.contract.view'))
            ->assertRedirect();
    }
}
