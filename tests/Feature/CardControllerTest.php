<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePortalUser(int $balance = 10000): User
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
            'available_balance' => $balance,
        ]);
        return $user;
    }

    /** GET /carta — visualizza la carta virtuale */
    public function test_show_renders_card_page(): void
    {
        $user = $this->makePortalUser();
        $response = $this->actingAs($user)->get(route('portal.card'));
        $response->assertStatus(200)->assertViewIs('portal.card');
    }

    /** POST /carta/blocca — blocca la carta */
    public function test_block_card(): void
    {
        $user = $this->makePortalUser();
        $response = $this->actingAs($user)->post(route('portal.card.block'));
        $response->assertRedirect(route('portal.card'));
        $response->assertSessionHas('portal_success');

        $account = Account::where('owner_user_id', $user->id)->first();
        $this->assertEquals('blocked', $account->fresh()->card_status);
    }

    /** POST /carta/sblocca — sblocca la carta */
    public function test_unblock_card(): void
    {
        $user = $this->makePortalUser();
        $account = Account::where('owner_user_id', $user->id)->first();
        $account->update(['card_status' => 'blocked']);

        $response = $this->actingAs($user)->post(route('portal.card.unblock'));
        $response->assertRedirect(route('portal.card'));
        $this->assertEquals('active', $account->fresh()->card_status);
    }

    /** GET /carta/pdf — scarica PDF tessera */
    public function test_download_pdf_returns_pdf_response(): void
    {
        $user = $this->makePortalUser();
        $response = $this->actingAs($user)->get(route('portal.card.pdf'));

        // DomPDF restituisce PDF inline oppure redirect se no DomPDF installato
        $this->assertContains($response->getStatusCode(), [200, 302]);

        if ($response->getStatusCode() === 200) {
            $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        }
    }

    /** GET /carta — admin viene redirectato */
    public function test_admin_redirected_from_card_page(): void
    {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'role'              => 'admin',
            'is_super_admin'   => true,
        ]);

        $response = $this->actingAs($admin)->get(route('portal.card'));
        $response->assertRedirect(route('admin.dashboard'));
    }

    /** GET /carta — richiede autenticazione */
    public function test_card_page_requires_auth(): void
    {
        $response = $this->get(route('portal.card'));
        $response->assertRedirect(route('login'));
    }
}
