<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test API REST v1 (routes/api.php) + ApiTokenController (portale).
 *
 * - GET  /api/v1/me
 * - GET  /api/v1/transfers
 * - GET  /api/v1/transfers/{uuid}
 * - POST /api/v1/transfers
 * - Portale: index / create / store / show / destroy token
 */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // AUTH MIDDLEWARE
    // =========================================================================

    public function test_missing_authorization_header_returns_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer km_invalidtoken',
        ])->assertUnauthorized();
    }

    public function test_expired_token_returns_401(): void
    {
        [, $rawToken] = $this->makeApiToken(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer ' . $rawToken,
        ])->assertUnauthorized();
    }

    // =========================================================================
    // GET /api/v1/me
    // =========================================================================

    public function test_me_returns_company_and_account_info(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken();

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer ' . $rawToken,
        ])
            ->assertOk()
            ->assertJsonPath('company.id', $company->id)
            ->assertJsonPath('company.name', $company->name)
            ->assertJsonStructure([
                'company' => ['id', 'name', 'slug'],
                'account' => ['id', 'currency', 'balance', 'available_balance', 'status'],
            ]);
    }

    public function test_me_updates_last_used_at(): void
    {
        [$company, $account, $rawToken, $token] = $this->makeApiToken();

        $this->assertNull($token->last_used_at);

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer ' . $rawToken])->assertOk();

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    // =========================================================================
    // GET /api/v1/transfers
    // =========================================================================

    public function test_transfers_index_returns_paginated_list(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken();

        $this->getJson('/api/v1/transfers', [
            'Authorization' => 'Bearer ' . $rawToken,
        ])
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_transfers_index_only_shows_own_booked_transfers(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken();
        [$otherCompany, $otherAccount]  = $this->makeCompanyWithAccount();

        // Transfer booked per l'account corrente
        $ownTransfer = Transfer::create([
            'from_account_id' => $account->id,
            'to_account_id'   => $otherAccount->id,
            'amount'          => 100,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'test',
            'reference'       => 'T-' . Str::random(6),
            'booked_at'       => now(),
        ]);

        // Transfer di un altro account (non deve comparire)
        Transfer::create([
            'from_account_id' => $otherAccount->id,
            'to_account_id'   => $otherAccount->id,
            'amount'          => 200,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'test',
            'reference'       => 'T-' . Str::random(6),
            'booked_at'       => now(),
        ]);

        $resp = $this->getJson('/api/v1/transfers', ['Authorization' => 'Bearer ' . $rawToken])
            ->assertOk();

        $uuids = collect($resp->json('data'))->pluck('uuid')->toArray();
        $this->assertContains($ownTransfer->uuid, $uuids);
        $this->assertSame(1, $resp->json('meta.total'));
    }

    // =========================================================================
    // GET /api/v1/transfers/{uuid}
    // =========================================================================

    public function test_can_get_own_transfer_by_uuid(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken();
        [$otherCompany, $otherAccount]  = $this->makeCompanyWithAccount();

        $transfer = Transfer::create([
            'from_account_id' => $account->id,
            'to_account_id'   => $otherAccount->id,
            'amount'          => 300,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'test',
            'reference'       => 'T-' . Str::random(6),
            'booked_at'       => now(),
        ]);

        $this->getJson('/api/v1/transfers/' . $transfer->uuid, [
            'Authorization' => 'Bearer ' . $rawToken,
        ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $transfer->uuid)
            ->assertJsonPath('data.amount', 300)
            ->assertJsonPath('data.direction', 'debit');
    }

    public function test_cannot_get_other_companys_transfer(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken();
        [$otherCompany, $otherAccount]  = $this->makeCompanyWithAccount();

        $transfer = Transfer::create([
            'from_account_id' => $otherAccount->id,
            'to_account_id'   => $otherAccount->id,
            'amount'          => 500,
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'test',
            'reference'       => 'T-' . Str::random(6),
            'booked_at'       => now(),
        ]);

        $this->getJson('/api/v1/transfers/' . $transfer->uuid, [
            'Authorization' => 'Bearer ' . $rawToken,
        ])->assertNotFound();
    }

    // =========================================================================
    // POST /api/v1/transfers
    // =========================================================================

    public function test_read_only_token_cannot_post_transfer(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken(['abilities' => ['read']]);
        [$otherCompany, $otherAccount]  = $this->makeCompanyWithAccount();

        $this->postJson('/api/v1/transfers', [
            'to_account_id' => $otherAccount->id,
            'amount'        => 100,
        ], ['Authorization' => 'Bearer ' . $rawToken])
            ->assertForbidden();
    }

    public function test_write_token_can_create_transfer(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken(['abilities' => ['read', 'write']]);
        [$otherCompany, $otherAccount]  = $this->makeCompanyWithAccount();

        // Collega un utente al conto mittente
        $user = User::create([
            'name'                => 'API User',
            'email'               => 'apiuser-' . Str::random(8) . '@test.test',
            'password'            => 'secret',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'is_active'           => true,
            'email_verified_at'   => now(),
        ]);
        $account->update(['owner_user_id' => $user->id]);

        $response = $this->postJson('/api/v1/transfers', [
            'to_account_id' => $otherAccount->id,
            'amount'        => 200,
            'description'   => 'Test API payment',
        ], ['Authorization' => 'Bearer ' . $rawToken]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['uuid', 'amount', 'currency', 'direction', 'status'],
            ]);

        $this->assertSame(200, $response->json('data.amount'));
    }

    public function test_post_transfer_validates_required_fields(): void
    {
        [$company, $account, $rawToken] = $this->makeApiToken(['abilities' => ['read', 'write']]);

        $this->postJson('/api/v1/transfers', [], [
            'Authorization' => 'Bearer ' . $rawToken,
        ])->assertUnprocessable();
    }

    // =========================================================================
    // PORTALE: ApiTokenController
    // =========================================================================

    public function test_portal_api_tokens_index_requires_auth(): void
    {
        $this->get(route('portal.api-tokens.index'))
            ->assertRedirect(route('login'));
    }

    public function test_portal_user_can_view_api_tokens_index(): void
    {
        [$user] = $this->makePortalUser();

        $this->actingAs($user)
            ->get(route('portal.api-tokens.index'))
            ->assertOk();
    }

    public function test_portal_user_can_create_api_token(): void
    {
        [$user,, $company] = $this->makePortalUser();

        $response = $this->actingAs($user)
            ->withSession($this->stepUp())
            ->post(route('portal.api-tokens.store'), [
                'name'       => 'My integration',
                'abilities'  => ['read'],
            ]);

        $token = ApiToken::where('company_id', $company->id)->first();
        $this->assertNotNull($token);
        $this->assertSame('My integration', $token->name);
        $response->assertRedirect(route('portal.api-tokens.show', $token));
        $response->assertSessionHas('new_token_plain');
    }

    public function test_portal_user_can_revoke_api_token(): void
    {
        [$user,, $company] = $this->makePortalUser();
        [, $rawToken, $tokenModel] = $this->makeApiTokenForCompany($company, ['abilities' => ['read']]);

        $this->actingAs($user)
            ->withSession($this->stepUp())
            ->delete(route('portal.api-tokens.destroy', $tokenModel))
            ->assertRedirect(route('portal.api-tokens.index'));

        $this->assertNull(ApiToken::find($tokenModel->id));
    }

    public function test_portal_other_company_cannot_view_token(): void
    {
        [$user] = $this->makePortalUser();
        [, $otherCompany] = $this->makeCompanyWithAccount();
        [,, $tokenModel] = $this->makeApiTokenForCompany($otherCompany, ['abilities' => ['read']]);

        $this->actingAs($user)
            ->get(route('portal.api-tokens.show', $tokenModel))
            ->assertForbidden();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Crea un token API valido.
     * @return array{Company, Account, string, ApiToken}
     */
    private function makeApiToken(array $tokenOverrides = []): array
    {
        [$company, $account] = $this->makeCompanyWithAccount();
        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        $token = ApiToken::create(array_merge([
            'company_id'   => $company->id,
            'name'         => 'Test token',
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read'],
        ], $tokenOverrides));

        return [$company, $account, $raw, $token];
    }

    /**
     * Crea un token per una company esistente.
     * @return array{Company, string, ApiToken}
     */
    private function makeApiTokenForCompany(Company $company, array $overrides = []): array
    {
        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        $token = ApiToken::create(array_merge([
            'company_id'   => $company->id,
            'name'         => 'Token ' . Str::random(4),
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read'],
        ], $overrides));

        return [$company, $raw, $token];
    }

    /** @return array{Company, Account} */
    private function makeCompanyWithAccount(int $balance = 10000): array
    {
        $company = Company::create([
            'name'          => 'APICo ' . Str::random(4),
            'slug'          => 'apico-' . Str::random(6),
            'email'         => 'api-' . Str::random(6) . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
        ]);

        $account = Account::create([
            'company_id'             => $company->id,
            'owner_type'             => 'company',
            'type'                   => 'primary',
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'available_balance'      => $balance,
            'allow_negative_balance' => true,
        ]);

        return [$company, $account];
    }

    /** @return array{User, Account, Company} */
    private function makePortalUser(): array
    {
        [$company, $account] = $this->makeCompanyWithAccount();

        $user = User::create([
            'name'                => 'Portal User',
            'email'               => 'portal-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        return [$user, $account, $company];
    }

    private function stepUp(): array
    {
        return ['step_up_verified_at' => now()->timestamp];
    }
}
