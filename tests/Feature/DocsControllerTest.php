<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePortalUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at'       => now(),
            'two_factor_confirmed_at' => null,
        ]);
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        Account::factory()->create([
            'company_id'    => $company->id,
            'owner_user_id' => $user->id,
            'status'        => 'active',
            'currency_code' => 'KY',
            'balance'       => 10000,
        ]);
        return $user;
    }

    /** GET /docs/api — pagina documentazione visibile agli utenti autenticati */
    public function test_api_docs_visible_to_authenticated_user(): void
    {
        $user = $this->makePortalUser();
        $response = $this->actingAs($user)->get(route('portal.docs-api'));
        $response->assertOk()->assertViewIs('portal.docs-api');
    }

    /** GET /docs/api — richiede autenticazione */
    public function test_api_docs_requires_auth(): void
    {
        $response = $this->get(route('portal.docs-api'));
        $response->assertRedirect(route('login'));
    }

    /** GET /docs/api — contiene sezioni principali */
    public function test_api_docs_contains_key_sections(): void
    {
        $user = $this->makePortalUser();
        $response = $this->actingAs($user)->get(route('portal.docs-api'));
        $response->assertSee('Authorization');
        $response->assertSee('/api/v1/');
    }
}
