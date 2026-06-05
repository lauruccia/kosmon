<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BeneficiaryControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeCompanyUser(): array
    {
        $slug = 'bene-co-' . Str::random(4);

        $company = Company::create([
            'name'          => 'Bene Co ' . Str::random(4),
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
            'name'                => 'Bene User',
            'email'               => 'beneuser-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        $account = Account::create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'owner_type'        => 'company',
            'type'              => 'primary',
            'account_name'      => 'Conto Bene',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 5000,
        ]);

        return [$user, $account];
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get(route('portal.beneficiaries.index'))
            ->assertRedirect(route('login'));
    }

    public function test_user_can_view_beneficiaries_page(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.beneficiaries.index'))
            ->assertOk()
            ->assertSee('Beneficiari');
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_user_can_save_a_beneficiary(): void
    {
        [$user, $account] = $this->makeCompanyUser();
        [$other, $otherAccount] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.beneficiaries.store'), [
                'beneficiary_account_id' => $otherAccount->id,
                'alias'                  => 'Fornitore principale',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('saved_beneficiaries', [
            'owner_account_id'       => $account->id,
            'beneficiary_account_id' => $otherAccount->id,
        ]);
    }

    public function test_store_rejects_saving_self_as_beneficiary(): void
    {
        [$user, $account] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.beneficiaries.store'), [
                'beneficiary_account_id' => $account->id,
            ])
            ->assertStatus(422);
    }

    public function test_store_requires_valid_account_id(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.beneficiaries.store'), [
                'beneficiary_account_id' => 99999,
            ])
            ->assertSessionHasErrors('beneficiary_account_id');
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function test_user_can_search_for_accounts(): void
    {
        [$user] = $this->makeCompanyUser();
        [$other, $otherAccount] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.beneficiaries.search', ['q' => 'Bene']))
            ->assertOk()
            ->assertJsonStructure([['id', 'display_name']]);
    }
}
