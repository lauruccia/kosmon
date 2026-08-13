<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il nuovo ruolo "Gestore Aziende e Prodotti" (2026-08-12, richiesta di Laura):
 * un operatore di backoffice che puo' SOLO creare/modificare aziende e prodotti shop per
 * conto delle aziende, e niente altro a livello amministrativo/gestionale (niente utenti,
 * ruoli, conti, movimenti, MLM, audit, impostazioni...). Vedi EnsureCanAccessBackoffice.
 */
class CompanyListingsOperatorRoleTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        $slug = 'op-co-' . Str::random(6);

        return Company::create([
            'name'          => 'Operator Test Co ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);
    }

    private function makeOperator(): User
    {
        $this->seed();

        $role = Role::where('slug', 'company-listings-operator')->firstOrFail();

        $user = User::create([
            'name'                => 'Gestore Aziende',
            'email'               => 'gestore-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'role'                => 'company-listings-operator',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();
        $user->roles()->sync([$role->id]);

        return $user->fresh();
    }

    public function test_operator_can_manage_companies_and_listings(): void
    {
        $operator = $this->makeOperator();
        $company = $this->makeCompany();

        $this->actingAs($operator)->get('/admin/companies')->assertOk();
        $this->actingAs($operator)->get('/admin/companies/' . $company->id)->assertOk();
        $this->actingAs($operator)->get('/admin/listings')->assertOk();
        $this->actingAs($operator)->get('/admin/listings/crea')->assertOk();
        $this->actingAs($operator)->get('/admin/listings/categorie')->assertOk();

        $this->actingAs($operator)
            ->post('/admin/companies/' . $company->id . '/address', [
                'city'    => 'Roma',
                'address' => 'Via Test 1',
            ])
            ->assertRedirect();
    }

    public function test_operator_cannot_open_unrelated_admin_sections(): void
    {
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get('/admin')->assertForbidden();
        $this->actingAs($operator)->get('/admin/users')->assertForbidden();
        $this->actingAs($operator)->get('/admin/roles')->assertForbidden();
        $this->actingAs($operator)->get('/admin/accounts')->assertForbidden();
        $this->actingAs($operator)->get('/admin/transfers')->assertForbidden();
        $this->actingAs($operator)->get('/admin/audit')->assertForbidden();
        $this->actingAs($operator)->get('/admin/limits')->assertForbidden();
        $this->actingAs($operator)->get('/admin/richieste-fido')->assertForbidden();
        $this->actingAs($operator)->get('/admin/listings/ordini')->assertForbidden();
    }

    public function test_login_redirects_operator_straight_to_companies(): void
    {
        $operator = $this->makeOperator();
        $operator->password = 'secret123';
        $operator->save();

        $response = $this->post('/login', [
            'email'    => $operator->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.companies.index'));
    }

    public function test_superadmin_and_backoffice_operator_are_unaffected(): void
    {
        $this->seed();
        $admin = User::where('email', 'superadmin@kmoney.test')->firstOrFail();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/companies')->assertOk();
        $this->actingAs($admin)->get('/admin/listings')->assertOk();
    }
}
