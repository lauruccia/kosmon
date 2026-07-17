<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Integrazione e-commerce gestita dall'admin (2026-07-17).
 *
 * L'admin del circuito configura i plugin WooCommerce dei clienti senza
 * accedere con l'account del negozio: da /admin/companies/{id} genera token
 * API (read+write) e webhook payment_request.paid per conto dell'azienda.
 */
class AdminCompanyEcommerceTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin Ecom',
            'email'               => 'admin-ecom-' . Str::random(6) . '@test.test',
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
        $slug = 'ecom-' . Str::random(6);

        $company = Company::create([
            'name'          => 'Ecom ' . Str::random(4),
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
            'name'                => 'Ecom User',
            'email'               => 'ecom-' . Str::random(8) . '@test.test',
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
            'account_name'      => 'Conto Ecom',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => $balance,
        ]);

        return [$user, $company, $account];
    }

    // ── Token API ─────────────────────────────────────────────────────────────

    public function test_admin_can_create_api_token_for_company(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        $response = $this->actingAs($admin)
            ->from(route('admin.companies.show', $company))
            ->post(route('admin.companies.ecommerce.token', $company), [
                'name' => 'WooCommerce negozio',
            ]);

        $response->assertRedirect(route('admin.companies.show', $company));
        $response->assertSessionHas('ecommerce_token_plain');

        $token = ApiToken::where('company_id', $company->id)->first();
        $this->assertNotNull($token);
        $this->assertSame('WooCommerce negozio', $token->name);
        $this->assertEqualsCanonicalizing(['read', 'write'], $token->abilities);
        $this->assertSame($admin->id, $token->created_by);
        $this->assertNull($token->expires_at);

        // Il token in chiaro flashato corrisponde all'hash salvato.
        $plain = session('ecommerce_token_plain');
        $this->assertStringStartsWith('km_', $plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        $this->assertTrue(AuditLog::where('event', 'admin.company.api_token_created')
            ->where('auditable_id', $token->id)->exists());
    }

    public function test_admin_created_token_authenticates_on_api_v1(): void
    {
        $admin = $this->makeAdmin();
        [, $company, $account] = $this->makeCompanyUser(12345);

        $this->actingAs($admin)->post(route('admin.companies.ecommerce.token', $company), [
            'name' => 'WooCommerce negozio',
        ]);

        $plain = session('ecommerce_token_plain');

        // Il token generato dall'admin funziona come quello del negoziante:
        // GET /balance risponde con il conto dell'azienda (usato dal plugin
        // per la regola "conto in negativo -> 100%").
        $response = $this->getJson('/api/v1/balance', [
            'Authorization' => 'Bearer ' . $plain,
        ]);

        $response->assertOk()
            ->assertJsonPath('account_number', $account->account_number)
            ->assertJsonPath('balance', 12345)
            ->assertJsonPath('is_in_debit', false);
    }

    public function test_admin_can_revoke_company_token(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        [, $hash, $prefix] = ApiToken::generateRaw();
        $token = ApiToken::create([
            'company_id'   => $company->id,
            'created_by'   => $admin->id,
            'name'         => 'Da revocare',
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read', 'write'],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.companies.ecommerce.token.revoke', [$company, $token]))
            ->assertRedirect();

        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
        $this->assertTrue(AuditLog::where('event', 'admin.company.api_token_revoked')->exists());
    }

    public function test_admin_cannot_revoke_token_of_another_company(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();
        [, $other] = $this->makeCompanyUser();

        [, $hash, $prefix] = ApiToken::generateRaw();
        $token = ApiToken::create([
            'company_id'   => $other->id,
            'created_by'   => $admin->id,
            'name'         => 'Di altra azienda',
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read'],
        ]);

        // {company} e {apiToken} non coerenti -> 404, il token resta.
        $this->actingAs($admin)
            ->delete(route('admin.companies.ecommerce.token.revoke', [$company, $token]))
            ->assertNotFound();

        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function test_admin_can_create_webhook_for_company(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        $response = $this->actingAs($admin)->post(route('admin.companies.ecommerce.webhook', $company), [
            'url' => 'https://negozio-cliente.it/?wc-api=wc_gateway_kmoney',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('ecommerce_webhook_secret');

        $webhook = Webhook::where('company_id', $company->id)->first();
        $this->assertNotNull($webhook);
        $this->assertSame('https://negozio-cliente.it/?wc-api=wc_gateway_kmoney', $webhook->url);
        $this->assertSame(['payment_request.paid'], $webhook->events);
        $this->assertTrue($webhook->is_active);
        $this->assertSame($webhook->secret, session('ecommerce_webhook_secret'));

        $this->assertTrue(AuditLog::where('event', 'admin.company.webhook_created')
            ->where('auditable_id', $webhook->id)->exists());
    }

    public function test_webhook_url_must_be_valid(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        $this->actingAs($admin)->post(route('admin.companies.ecommerce.webhook', $company), [
            'url' => 'non-un-url',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, Webhook::where('company_id', $company->id)->count());
    }

    public function test_admin_can_toggle_and_delete_webhook(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        $webhook = Webhook::create([
            'company_id' => $company->id,
            'url'        => 'https://negozio-cliente.it/?wc-api=wc_gateway_kmoney',
            'events'     => ['payment_request.paid'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.webhook.toggle', [$company, $webhook]))
            ->assertRedirect();
        $this->assertFalse($webhook->fresh()->is_active);

        $this->actingAs($admin)
            ->post(route('admin.companies.ecommerce.webhook.toggle', [$company, $webhook]))
            ->assertRedirect();
        $this->assertTrue($webhook->fresh()->is_active);

        $this->actingAs($admin)
            ->delete(route('admin.companies.ecommerce.webhook.delete', [$company, $webhook]))
            ->assertRedirect();
        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
        $this->assertTrue(AuditLog::where('event', 'admin.company.webhook_deleted')->exists());
    }

    public function test_admin_cannot_touch_webhook_of_another_company(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();
        [, $other] = $this->makeCompanyUser();

        $webhook = Webhook::create([
            'company_id' => $other->id,
            'url'        => 'https://altro.it/?wc-api=wc_gateway_kmoney',
            'events'     => ['payment_request.paid'],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.companies.ecommerce.webhook.delete', [$company, $webhook]))
            ->assertNotFound();

        $this->assertDatabaseHas('webhooks', ['id' => $webhook->id]);
    }

    // ── Autorizzazione ────────────────────────────────────────────────────────

    public function test_non_admin_cannot_use_ecommerce_admin_endpoints(): void
    {
        [$user, $company] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('admin.companies.ecommerce.token', $company), ['name' => 'X'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.companies.ecommerce.webhook', $company), ['url' => 'https://x.it/'])
            ->assertForbidden();

        $this->assertSame(0, ApiToken::where('company_id', $company->id)->count());
        $this->assertSame(0, Webhook::where('company_id', $company->id)->count());
    }

    public function test_company_show_page_lists_tokens_and_webhooks(): void
    {
        $admin = $this->makeAdmin();
        [, $company] = $this->makeCompanyUser();

        [, $hash, $prefix] = ApiToken::generateRaw();
        ApiToken::create([
            'company_id'   => $company->id,
            'created_by'   => $admin->id,
            'name'         => 'Token in lista',
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read', 'write'],
        ]);
        Webhook::create([
            'company_id' => $company->id,
            'url'        => 'https://negozio-in-lista.it/?wc-api=wc_gateway_kmoney',
            'events'     => ['payment_request.paid'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.companies.show', $company))
            ->assertOk()
            ->assertSee('Integrazione e-commerce')
            ->assertSee('Token in lista')
            ->assertSee('negozio-in-lista.it');
    }
}
