<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListingAnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'               => 'admin-la-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    private function makeCompanyUser(): array
    {
        $slug = 'la-co-' . Str::random(4);

        $company = Company::create([
            'name'          => 'LA Company ' . Str::random(3),
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
            'name'                => 'LA User',
            'email'               => 'lauser-' . Str::random(6) . '@test.test',
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
            'account_name'      => 'Conto LA',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return [$user, $company];
    }

    // ── Shop / Listing (portal) ────────────────────────────────────────────────

    public function test_shop_index_requires_authentication(): void
    {
        $this->get(route('portal.shop'))
            ->assertRedirect(route('login'));
    }

    public function test_company_user_can_view_shop(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.shop'))
            ->assertOk()
            ->assertSee('Shop', false);
    }

    public function test_company_user_can_view_shop_create_form(): void
    {
        // Dal 29/07: pubblicare prodotti non richiede piu' il piano
        // Ecommerce (Company::hasEcommercePlan()), ma la sola presenza in
        // directory (status attivo + KYC approvato, Company::isInDirectory()) —
        // makeCompanyUser() crea gia' l'azienda active/approved di default,
        // quindi nessun piano da attivare esplicitamente.
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.shop.create'))
            ->assertOk();
    }

    public function test_company_user_without_ecommerce_plan_can_still_publish_when_in_directory(): void
    {
        // Verifica esplicitamente che il vincolo sul piano Ecommerce sia
        // stato rimosso (2026-07-29): un'azienda senza alcun piano
        // (plan_id null) ma presente in directory deve poter pubblicare.
        [$user, $company] = $this->makeCompanyUser();
        $company->update(['plan_id' => null]);
        $this->assertFalse($company->fresh()->hasEcommercePlan());

        $this->actingAs($user)
            ->get(route('portal.shop.create'))
            ->assertOk();
    }

    public function test_company_user_not_in_directory_is_redirected_from_shop_create(): void
    {
        // Un'azienda non ancora approvata KYC (quindi non presente in
        // directory) non puo' pubblicare prodotti, anche con un piano
        // Ecommerce attivo.
        [$user, $company] = $this->makeCompanyUser();
        $company->update(['kyc_status' => 'pending']);
        $ecommercePlan = \App\Models\Plan::where('can_sell_products', true)->first()
            ?? \App\Models\Plan::factory()->create(['can_sell_products' => true]);
        $company->update(['plan_id' => $ecommercePlan->id]);

        $this->actingAs($user)
            ->get(route('portal.shop.create'))
            ->assertRedirect(route('portal.shop'))
            ->assertSessionHas('portal_error');
    }

    // ── Shop / Listing (admin) ────────────────────────────────────────────────

    public function test_admin_can_view_listings_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.listings.index'))
            ->assertOk();
    }

    public function test_admin_can_update_listing_status(): void
    {
        $admin             = $this->makeAdmin();
        [$user, $company] = $this->makeCompanyUser();

        $listing = Listing::create([
            'company_id'         => $company->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Test Listing',
            'description'        => 'Una descrizione del prodotto',
            'price_ky'           => 1000,
            'category'           => 'informatica',
            'status'             => 'draft',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.listings.status', $listing), ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $listing->fresh()->status);
    }

    // ── Announcements (portal) ────────────────────────────────────────────────

    public function test_announcements_index_requires_authentication(): void
    {
        $this->get(route('portal.announcements'))
            ->assertRedirect(route('login'));
    }

    public function test_company_user_can_view_announcements(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.announcements'))
            ->assertOk()
            ->assertSee('Annunci', false);
    }

    public function test_company_user_can_view_announcement_create_form(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->get(route('portal.announcements.create'))
            ->assertOk();
    }

    public function test_company_user_can_create_announcement(): void
    {
        [$user, $company] = $this->makeCompanyUser();

        $this->actingAs($user)
            ->post(route('portal.announcements.store'), [
                'title'       => 'Cerco fornitore alimentare',
                'body'        => 'Siamo alla ricerca di un fornitore di prodotti biologici per la nostra azienda.',
                'type'        => 'request',
                'sector'      => 'alimentari',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'company_id' => $company->id,
            'title'      => 'Cerco fornitore alimentare',
        ]);
    }

    // ── Announcements (admin) ─────────────────────────────────────────────────

    public function test_admin_can_view_announcements_index(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.announcements.index'))
            ->assertOk();
    }

    public function test_admin_can_update_announcement_status(): void
    {
        $admin             = $this->makeAdmin();
        [$user, $company] = $this->makeCompanyUser();

        $announcement = Announcement::create([
            'company_id'         => $company->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Annuncio Test',
            'body'               => 'Corpo annuncio di test per la piattaforma KMoney.',
            'type'               => 'offer',
            'sector'             => 'informatica',
            'status'             => 'draft',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.announcements.status', $announcement), ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame('active', $announcement->fresh()->status);
    }
}
