<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test HTTP layer WebhookController.
 * Verifica accesso, ownership, CRUD e azioni toggle/test.
 */
class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Index
    // -------------------------------------------------------------------------

    public function test_user_can_view_webhook_index(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.webhooks.index'))
            ->assertOk();
    }

    public function test_webhook_index_requires_authentication(): void
    {
        $this->get(route('portal.webhooks.index'))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function test_user_can_view_webhook_create(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.webhooks.create'))
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_user_can_create_webhook(): void
    {
        [$user, $account, $company] = $this->makeCompanyUser();

        $response = $this->actingAs($user)
            ->post(route('portal.webhooks.store'), [
                'url'    => 'https://example.com/webhook',
                'events' => ['transfer.booked'],
            ]);

        $webhook = Webhook::where('company_id', $company->id)->first();
        $this->assertNotNull($webhook);
        $this->assertSame('https://example.com/webhook', $webhook->url);
        $this->assertSame(['transfer.booked'], $webhook->events);
        $this->assertTrue($webhook->is_active);

        $response->assertRedirect(route('portal.webhooks.show', $webhook));
    }

    public function test_webhook_store_validates_url_is_required(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.webhooks.store'), [
                'url'    => '',
                'events' => ['transfer.booked'],
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_webhook_store_validates_events_required(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.webhooks.store'), [
                'url'    => 'https://example.com/wh',
                'events' => [],
            ])
            ->assertSessionHasErrors('events');
    }

    public function test_webhook_store_rejects_invalid_event(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.webhooks.store'), [
                'url'    => 'https://example.com/wh',
                'events' => ['fake.event.nonexistent'],
            ])
            ->assertSessionHasErrors('events.0');
    }

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    public function test_owner_can_view_webhook(): void
    {
        [$user, $account, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company);

        $this->actingAs($user)
            ->get(route('portal.webhooks.show', $webhook))
            ->assertOk();
    }

    public function test_other_company_cannot_view_webhook(): void
    {
        [$user] = $this->makeCompanyUser();
        [,, $otherCompany] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($otherCompany);

        $this->actingAs($user)
            ->get(route('portal.webhooks.show', $webhook))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Toggle
    // -------------------------------------------------------------------------

    public function test_owner_can_toggle_webhook_off(): void
    {
        [$user,, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company);
        $this->assertTrue($webhook->is_active);

        $this->actingAs($user)
            ->post(route('portal.webhooks.toggle', $webhook))
            ->assertRedirect();

        $this->assertFalse($webhook->fresh()->is_active);
    }

    public function test_owner_can_toggle_webhook_back_on(): void
    {
        [$user,, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company, ['is_active' => false]);

        $this->actingAs($user)
            ->post(route('portal.webhooks.toggle', $webhook))
            ->assertRedirect();

        $this->assertTrue($webhook->fresh()->is_active);
    }

    public function test_other_company_cannot_toggle_webhook(): void
    {
        [$user] = $this->makeCompanyUser();
        [,, $otherCompany] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($otherCompany);

        $this->actingAs($user)
            ->post(route('portal.webhooks.toggle', $webhook))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Test (ping)
    // -------------------------------------------------------------------------

    public function test_owner_can_send_test_event(): void
    {
        Queue::fake();

        [$user,, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company);

        $this->actingAs($user)
            ->post(route('portal.webhooks.test', $webhook))
            ->assertRedirect();

        Queue::assertPushed(\App\Jobs\SendWebhookJob::class);
    }

    public function test_inactive_webhook_cannot_receive_test(): void
    {
        [$user,, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company, ['is_active' => false]);

        $this->actingAs($user)
            ->post(route('portal.webhooks.test', $webhook))
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_webhook(): void
    {
        [$user,, $company] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($company);

        $this->actingAs($user)
            ->delete(route('portal.webhooks.destroy', $webhook))
            ->assertRedirect(route('portal.webhooks.index'));

        $this->assertNull(Webhook::find($webhook->id));
    }

    public function test_other_company_cannot_delete_webhook(): void
    {
        [$user] = $this->makeCompanyUser();
        [,, $otherCompany] = $this->makeCompanyUser();
        $webhook = $this->makeWebhook($otherCompany);

        $this->actingAs($user)
            ->delete(route('portal.webhooks.destroy', $webhook))
            ->assertForbidden();

        $this->assertNotNull(Webhook::find($webhook->id));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Crea Company + User + Account principale aziendale.
     * @return array{User, Account, Company}
     */
    private function makeCompanyUser(): array
    {
        $company = Company::create([
            'name'         => 'WebhookCo ' . Str::random(4),
            'slug'         => 'webhookco-' . Str::random(6),
            'email'        => 'wh-' . Str::random(6) . '@test.test',
            'status'       => 'active',
            'kyc_status'   => 'approved',
            'currency_code'=> 'KY',
        ]);

        $user = User::create([
            'name'                => 'WH User',
            'email'               => 'whuser-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'company',
            'company_id'          => $company->id,
            'is_active'           => true,
            'is_super_admin'      => false,
            'email_verified_at'   => now(),
            'contract_signed_at'  => now(),
        ]);

        $account = Account::create([
            'company_id'            => $company->id,
            'owner_type'            => 'company',
            'type'                  => 'primary',
            'currency_code'         => 'KY',
            'status'                => 'active',
            'available_balance'     => 5000,
            'allow_negative_balance'=> false,
        ]);

        return [$user, $account, $company];
    }

    private function makeWebhook(Company $company, array $overrides = []): Webhook
    {
        return Webhook::create(array_merge([
            'company_id' => $company->id,
            'url'        => 'https://hooks.example.com/' . Str::random(6),
            'events'     => ['transfer.booked'],
            'is_active'  => true,
        ], $overrides));
    }
}
