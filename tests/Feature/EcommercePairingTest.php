<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EcommercePairing;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Collegamento plugin e-commerce col solo numero di conto (2026-07-20).
 *
 * Il plugin invia numero di conto + URL sito + claim_secret; l'admin approva
 * da /admin/companies/{id}; il plugin ritira token API e secret webhook una
 * sola volta, autenticandosi con il claim_secret.
 */
class EcommercePairingTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin Pairing',
            'email'               => 'admin-pair-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeCompanyUser(int $balance = 0): array
    {
        $slug = 'pair-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Pair ' . Str::random(4),
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
            'name'                => 'Pair User',
            'email'               => 'pair-' . Str::random(8) . '@test.test',
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
            'account_name'      => 'Conto Pair',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => $balance,
        ]);

        return [$user, $company, $account];
    }

    /** Payload valido di creazione pairing per il conto dato. */
    private function pairingPayload(Account $account, array $overrides = []): array
    {
        return array_merge([
            'account_number' => $account->uuid,
            'site_url'       => 'https://negozio.test/',
            'webhook_url'    => 'https://negozio.test/?wc-api=wc_gateway_kmoney',
            'claim_secret'   => str_repeat('s', 40),
            'platform'       => 'woocommerce',
        ], $overrides);
    }

    // ── Creazione pairing (API pubblica) ─────────────────────────────────────

    public function test_plugin_can_request_pairing_with_account_number(): void
    {
        [, $company, $account] = $this->makeCompanyUser();

        $response = $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account));

        $response->assertStatus(201)->assertJsonPath('status', 'pending');

        $pairing = EcommercePairing::first();
        $this->assertNotNull($pairing);
        $this->assertSame($company->id, $pairing->company_id);
        $this->assertSame($account->id, $pairing->account_id);
        $this->assertSame($account->uuid, $pairing->account_number);
        $this->assertSame(hash('sha256', str_repeat('s', 40)), $pairing->claim_secret_hash);
        $this->assertNull($pairing->credentials);

        $this->assertTrue(AuditLog::where('event', 'ecommerce.pairing_requested')
            ->where('auditable_id', $pairing->id)->exists());
    }

    public function test_pairing_normalizes_account_number_with_spaces_and_lowercase(): void
    {
        [, , $account] = $this->makeCompanyUser();

        $mangled = strtolower(substr($account->uuid, 0, 4) . ' ' . substr($account->uuid, 4));

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account, [
            'account_number' => $mangled,
        ]))->assertStatus(201);

        $this->assertSame($account->uuid, EcommercePairing::first()->account_number);
    }

    public function test_pairing_rejects_unknown_or_invalid_account_number(): void
    {
        $this->makeCompanyUser();

        // Formato non valido.
        $this->postJson('/api/v1/ecommerce/pairings', [
            'account_number' => 'ABC123',
            'site_url'       => 'https://negozio.test/',
            'webhook_url'    => 'https://negozio.test/?wc-api=wc_gateway_kmoney',
            'claim_secret'   => str_repeat('s', 40),
        ])->assertStatus(422);

        // Formato valido ma conto inesistente.
        $this->postJson('/api/v1/ecommerce/pairings', [
            'account_number' => 'KYB' . str_repeat('Z', 13),
            'site_url'       => 'https://negozio.test/',
            'webhook_url'    => 'https://negozio.test/?wc-api=wc_gateway_kmoney',
            'claim_secret'   => str_repeat('s', 40),
        ])->assertStatus(422);

        $this->assertSame(0, EcommercePairing::count());
    }

    public function test_new_pairing_replaces_previous_pending_for_same_site_and_account(): void
    {
        [, , $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $first = EcommercePairing::first();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account, [
            'claim_secret' => str_repeat('t', 40),
        ]))->assertStatus(201);

        $this->assertSame(1, EcommercePairing::count());
        $this->assertNotSame($first->id, EcommercePairing::first()->id);
    }

    // ── Approvazione / rifiuto admin ─────────────────────────────────────────

    public function test_admin_can_approve_pairing_creating_token_and_webhook(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        $response = $this->actingAs($admin)
            ->from(route('admin.companies.show', $company))
            ->post(route('admin.companies.ecommerce.pairing.approve', [$company, $pairing]));

        $response->assertRedirect(route('admin.companies.show', $company));

        $pairing->refresh();
        $this->assertSame(EcommercePairing::STATUS_APPROVED, $pairing->status);
        $this->assertSame($admin->id, $pairing->approved_by);
        $this->assertNotNull($pairing->approved_at);
        $this->assertNull($pairing->claimed_at);

        // Token read+write e webhook payment_request.paid creati per l'azienda.
        $token = ApiToken::find($pairing->api_token_id);
        $this->assertNotNull($token);
        $this->assertSame($company->id, $token->company_id);
        $this->assertEqualsCanonicalizing(['read', 'write'], $token->abilities);

        $webhook = Webhook::find($pairing->webhook_id);
        $this->assertNotNull($webhook);
        $this->assertSame($company->id, $webhook->company_id);
        $this->assertSame('https://negozio.test/?wc-api=wc_gateway_kmoney', $webhook->url);
        $this->assertSame(['payment_request.paid'], $webhook->events);

        // Credenziali cifrate pronte per il ritiro, coerenti con token/webhook.
        $this->assertIsArray($pairing->credentials);
        $this->assertSame(hash('sha256', $pairing->credentials['api_token']), $token->token_hash);
        $this->assertSame($webhook->secret, $pairing->credentials['webhook_secret']);

        $this->assertTrue(AuditLog::where('event', 'admin.company.ecommerce_pairing_approved')
            ->where('auditable_id', $pairing->id)->exists());
    }

    public function test_admin_can_reject_pairing(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.pairing.reject', [$company, $pairing]));

        $pairing->refresh();
        $this->assertSame(EcommercePairing::STATUS_REJECTED, $pairing->status);
        $this->assertNull($pairing->api_token_id);
        $this->assertSame(0, ApiToken::count());
        $this->assertSame(0, Webhook::count());

        $this->assertTrue(AuditLog::where('event', 'admin.company.ecommerce_pairing_rejected')
            ->where('auditable_id', $pairing->id)->exists());
    }

    public function test_non_admin_cannot_approve_pairing(): void
    {
        [$user, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        $this->actingAs($user)
            ->post(route('admin.companies.ecommerce.pairing.approve', [$company, $pairing]))
            ->assertStatus(403);

        $this->assertSame(EcommercePairing::STATUS_PENDING, $pairing->fresh()->status);
    }

    public function test_admin_cannot_approve_pairing_of_another_company(): void
    {
        $admin = $this->makeAdmin();
        [, , $account] = $this->makeCompanyUser();
        [, $otherCompany] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.pairing.approve', [$otherCompany, $pairing]))
            ->assertStatus(404);

        $this->assertSame(EcommercePairing::STATUS_PENDING, $pairing->fresh()->status);
    }

    // ── Ritiro credenziali dal plugin ────────────────────────────────────────

    public function test_plugin_claims_credentials_once_with_claim_secret(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        // Prima dell'approvazione: solo lo stato, nessuna credenziale.
        $this->getJson('/api/v1/ecommerce/pairings/' . $pairing->uuid . '?claim_secret=' . str_repeat('s', 40))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('api_token');

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.pairing.approve', [$company, $pairing]));

        // Primo ritiro: credenziali consegnate, valide sull'API v1.
        $claim = $this->getJson('/api/v1/ecommerce/pairings/' . $pairing->uuid . '?claim_secret=' . str_repeat('s', 40))
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $apiToken      = $claim->json('api_token');
        $webhookSecret = $claim->json('webhook_secret');
        $this->assertStringStartsWith('km_', $apiToken);
        $this->assertNotEmpty($webhookSecret);

        $this->getJson('/api/v1/balance', ['Authorization' => 'Bearer ' . $apiToken])->assertOk();

        // Dopo il ritiro: credenziali azzerate, mai più consegnate.
        $pairing->refresh();
        $this->assertNull($pairing->credentials);
        $this->assertNotNull($pairing->claimed_at);

        $this->getJson('/api/v1/ecommerce/pairings/' . $pairing->uuid . '?claim_secret=' . str_repeat('s', 40))
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('claimed', true)
            ->assertJsonMissingPath('api_token');

        $this->assertTrue(AuditLog::where('event', 'ecommerce.pairing_claimed')
            ->where('auditable_id', $pairing->id)->exists());
    }

    public function test_claim_requires_correct_secret(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);
        $pairing = EcommercePairing::first();

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.pairing.approve', [$company, $pairing]));

        // Secret errato o mancante → 404, credenziali intatte.
        $this->getJson('/api/v1/ecommerce/pairings/' . $pairing->uuid . '?claim_secret=' . str_repeat('x', 40))
            ->assertStatus(404);
        $this->getJson('/api/v1/ecommerce/pairings/' . $pairing->uuid)
            ->assertStatus(404);

        $pairing->refresh();
        $this->assertIsArray($pairing->credentials);
        $this->assertNull($pairing->claimed_at);
    }

    // ── Pagina admin ─────────────────────────────────────────────────────────

    public function test_company_show_lists_pending_pairing(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser();

        $this->postJson('/api/v1/ecommerce/pairings', $this->pairingPayload($account))->assertStatus(201);

        $this->actingAs($admin)
            ->get(route('admin.companies.show', $company))
            ->assertOk()
            ->assertSee('Richieste di collegamento in attesa')
            ->assertSee('negozio.test')
            ->assertSee($account->uuid);
    }
}
