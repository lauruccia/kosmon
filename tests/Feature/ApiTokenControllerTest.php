<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApiTokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): array
    {
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        $user = User::factory()->create([
            'company_id'              => $company->id,
            'email_verified_at'       => now(),
            'two_factor_confirmed_at' => null,
            'contract_signed_at'      => now(),
        ]);
        $account = Account::factory()->create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => 10000,
        ]);
        return [$user, $company, $account];
    }

    private function stepUp(): array
    {
        return ['step_up_verified_at' => now()->timestamp];
    }

    /** GET /api-tokens — index lista token */
    public function test_index_lists_company_tokens(): void
    {
        [$user, $company] = $this->makeUser();

        ApiToken::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->get(route('portal.api-tokens.index'));
        $response->assertOk()->assertViewIs('portal.api-tokens.index');
        $response->assertViewHas('tokens');
    }

    /** GET /api-tokens/nuovo — form creazione */
    public function test_create_form_visible(): void
    {
        [$user] = $this->makeUser();
        $response = $this->actingAs($user)->get(route('portal.api-tokens.create'));
        $response->assertOk()->assertViewIs('portal.api-tokens.create');
    }

    /** POST /api-tokens — crea token read-only */
    public function test_store_creates_read_token(): void
    {
        [$user, $company] = $this->makeUser();

        $response = $this->actingAs($user)->withSession($this->stepUp())->post(route('portal.api-tokens.store'), [
            'name'       => 'Test Token',
            'abilities'  => ['read'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('api_tokens', [
            'company_id' => $company->id,
            'name'       => 'Test Token',
        ]);
    }

    /** POST /api-tokens — crea token write */
    public function test_store_creates_write_token(): void
    {
        [$user, $company] = $this->makeUser();

        $response = $this->actingAs($user)->withSession($this->stepUp())->post(route('portal.api-tokens.store'), [
            'name'       => 'Write Token',
            'abilities'  => ['read', 'write'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('api_tokens', ['company_id' => $company->id, 'name' => 'Write Token']);
    }

    /** POST /api-tokens — validazione: name obbligatorio */
    public function test_store_requires_name(): void
    {
        [$user] = $this->makeUser();
        $response = $this->actingAs($user)->withSession($this->stepUp())->post(route('portal.api-tokens.store'), [
            'abilities' => ['read'],
        ]);
        $response->assertSessionHasErrors('name');
    }

    /** POST /api-tokens — validazione: abilities obbligatorie */
    public function test_store_requires_abilities(): void
    {
        [$user] = $this->makeUser();
        $response = $this->actingAs($user)->withSession($this->stepUp())->post(route('portal.api-tokens.store'), [
            'name' => 'No Abilities Token',
        ]);
        $response->assertSessionHasErrors('abilities');
    }

    /** GET /api-tokens/{token} — show token della propria azienda */
    public function test_show_own_token(): void
    {
        [$user, $company] = $this->makeUser();
        $token = ApiToken::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->get(route('portal.api-tokens.show', $token));
        $response->assertOk()->assertViewIs('portal.api-tokens.show');
    }

    /** GET /api-tokens/{token} — 403 se token di altra azienda */
    public function test_show_foreign_token_forbidden(): void
    {
        [$user] = $this->makeUser();
        [$other, $otherCompany] = $this->makeUser();
        $token = ApiToken::factory()->create(['company_id' => $otherCompany->id, 'created_by' => $other->id]);

        $response = $this->actingAs($user)->get(route('portal.api-tokens.show', $token));
        $response->assertForbidden();
    }

    /** DELETE /api-tokens/{token} — elimina token */
    public function test_destroy_token(): void
    {
        Notification::fake();
        [$user, $company] = $this->makeUser();
        $token = ApiToken::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);

        $response = $this->actingAs($user)->withSession($this->stepUp())->delete(route('portal.api-tokens.destroy', $token));
        $response->assertRedirect(route('portal.api-tokens.index'));
        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }

    /** DELETE /api-tokens/{token} — 403 se token altrui */
    public function test_destroy_foreign_token_forbidden(): void
    {
        [$user] = $this->makeUser();
        [$other, $otherCompany] = $this->makeUser();
        $token = ApiToken::factory()->create(['company_id' => $otherCompany->id, 'created_by' => $other->id]);

        $response = $this->actingAs($user)->withSession($this->stepUp())->delete(route('portal.api-tokens.destroy', $token));
        $response->assertForbidden();
    }
}
