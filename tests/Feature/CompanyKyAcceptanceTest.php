<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * % Kmoney accettata dichiarata dall'azienda nel profilo (2026-07-17).
 *
 * - L'azienda sceglie una % tra 0/25/50/75/100 dal profilo azienda.
 * - La % e' mostrata come badge sulla card della directory /aziende,
 *   scegliendo in automatico la migliore tra % dichiarata e migliore %
 *   (25-100) dei prodotti attivi.
 * - Conto sottozero: sempre 100%, non modificabile.
 * - Chi accetta di piu' viene mostrato prima (a parita' di piano).
 */
class CompanyKyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeCompanyUser(int $balance = 0, array $companyAttrs = []): array
    {
        $slug = 'kyacc-' . Str::random(6);

        $company = Company::create(array_merge([
            'name'          => 'KyAcc ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ], $companyAttrs));

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'KyAcc User',
            'email'               => 'kyacc-' . Str::random(8) . '@test.test',
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
            'account_name'      => 'Conto KyAcc',
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => $balance,
        ]);

        return [$user, $company, $account];
    }

    private function makeListing(Company $company, User $user, int $kyPercentage, string $status = 'active'): Listing
    {
        return Listing::create([
            'company_id'         => $company->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Prodotto ' . $kyPercentage,
            'description'        => 'Test',
            'category'           => 'informatica',
            'price_ky'           => 10000,
            'ky_percentage'      => $kyPercentage,
            'status'             => $status,
        ]);
    }

    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'sector'      => '',
            'tagline'     => 'Tagline test',
            'description' => 'Descrizione test',
            'city'        => 'Milano',
        ], $overrides);
    }

    // ── Profilo azienda: salvataggio % ────────────────────────────────────────

    public function test_company_can_declare_accepted_ky_percentage_from_profile(): void
    {
        [$user, $company] = $this->makeCompanyUser();

        $response = $this->actingAs($user)->post(route('portal.profile.update'),
            $this->profilePayload(['accepted_ky_percentage' => 50]));

        $response->assertRedirect(route('portal.profile.edit'));
        $this->assertSame(50, $company->fresh()->accepted_ky_percentage);
    }

    public function test_invalid_percentage_is_rejected(): void
    {
        [$user, $company] = $this->makeCompanyUser();

        $response = $this->actingAs($user)->post(route('portal.profile.update'),
            $this->profilePayload(['accepted_ky_percentage' => 30]));

        $response->assertSessionHasErrors('accepted_ky_percentage');
        $this->assertNull($company->fresh()->accepted_ky_percentage);
    }

    public function test_zero_percentage_is_a_valid_choice(): void
    {
        [$user, $company] = $this->makeCompanyUser();

        $this->actingAs($user)->post(route('portal.profile.update'),
            $this->profilePayload(['accepted_ky_percentage' => 0]));

        $this->assertSame(0, $company->fresh()->accepted_ky_percentage);
    }

    public function test_company_in_debit_cannot_change_percentage(): void
    {
        [$user, $company] = $this->makeCompanyUser(balance: -5000);
        $company->update(['accepted_ky_percentage' => 50]);

        $response = $this->actingAs($user)->post(route('portal.profile.update'),
            $this->profilePayload(['accepted_ky_percentage' => 25]));

        $response->assertRedirect(route('portal.profile.edit'));
        // Il valore dichiarato non cambia: sottozero la % e' bloccata
        $this->assertSame(50, $company->fresh()->accepted_ky_percentage);
    }

    public function test_profile_edit_page_shows_ky_acceptance_section(): void
    {
        [$user] = $this->makeCompanyUser();

        $this->actingAs($user)->get(route('portal.profile.edit'))
            ->assertOk()
            ->assertSee('Accettazione Kmoney')
            ->assertSee('accepted_ky_percentage');
    }

    public function test_profile_edit_page_shows_locked_notice_when_in_debit(): void
    {
        [$user] = $this->makeCompanyUser(balance: -5000);

        $response = $this->actingAs($user)->get(route('portal.profile.edit'));

        $response->assertOk()
            ->assertSee('sotto zero')
            ->assertDontSee('name="accepted_ky_percentage"', false);
    }

    // ── Percentuale effettiva (modello) ───────────────────────────────────────

    public function test_effective_percentage_picks_best_between_profile_and_listings(): void
    {
        [$user, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 50]);
        $this->makeListing($company, $user, 75);

        $this->assertSame(75, $company->fresh()->effectiveAcceptedKyPercentage());
    }

    public function test_effective_percentage_keeps_declared_when_higher_than_listings(): void
    {
        [$user, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 75]);
        $this->makeListing($company, $user, 25);

        $this->assertSame(75, $company->fresh()->effectiveAcceptedKyPercentage());
    }

    public function test_zero_percent_listings_and_inactive_listings_are_ignored(): void
    {
        [$user, $company] = $this->makeCompanyUser();
        $this->makeListing($company, $user, 0);
        $this->makeListing($company, $user, 100, status: 'suspended');

        $this->assertNull($company->fresh()->effectiveAcceptedKyPercentage());
    }

    public function test_effective_percentage_is_100_when_account_in_debit(): void
    {
        [, $company] = $this->makeCompanyUser(balance: -5000);
        $company->update(['accepted_ky_percentage' => 25]);

        $this->assertSame(100, $company->fresh()->effectiveAcceptedKyPercentage());
    }

    public function test_effective_percentage_is_null_when_nothing_declared(): void
    {
        [, $company] = $this->makeCompanyUser();

        $this->assertNull($company->fresh()->effectiveAcceptedKyPercentage());
    }

    // ── Directory: badge sulla card ───────────────────────────────────────────

    public function test_directory_shows_declared_percentage_badge(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 50]);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('Kmoney 50%');
    }

    public function test_directory_shows_gold_badge_for_full_acceptance(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 100]);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('ky-badge--gold')
            ->assertSee('★ 100% Kmoney');
    }

    public function test_directory_badge_uses_best_listing_percentage_automatically(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [$user, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 25]);
        $this->makeListing($company, $user, 75);

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertSee('Kmoney 75%')
            ->assertDontSee('Kmoney 25%');
    }

    public function test_directory_shows_no_percentage_badge_when_undeclared(): void
    {
        [$viewer] = $this->makeCompanyUser();
        $this->makeCompanyUser(); // azienda senza % dichiarata e senza prodotti

        $this->actingAs($viewer)->get(route('portal.companies'))
            ->assertOk()
            ->assertDontSee('★ 100% Kmoney')
            ->assertDontSee('✓ Kmoney');
    }

    public function test_directory_orders_higher_acceptance_first_within_same_plan(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, $low]  = $this->makeCompanyUser(companyAttrs: ['name' => 'Zeta Bassa AAA']);
        [, $high] = $this->makeCompanyUser(companyAttrs: ['name' => 'Alfa Alta ZZZ']);
        $low->update(['accepted_ky_percentage' => 25]);
        $high->update(['accepted_ky_percentage' => 100]);

        $html = $this->actingAs($viewer)->get(route('portal.companies'))->getContent();

        $posHigh = strpos($html, 'Alfa Alta ZZZ');
        $posLow  = strpos($html, 'Zeta Bassa AAA');
        $this->assertNotFalse($posHigh);
        $this->assertNotFalse($posLow);
        $this->assertLessThan($posLow, $posHigh, 'Chi accetta il 100% deve comparire prima di chi accetta il 25%.');
    }

    // ── Profilo pubblico azienda ──────────────────────────────────────────────

    public function test_public_company_profile_shows_acceptance_chip(): void
    {
        [$viewer] = $this->makeCompanyUser();
        [, $company] = $this->makeCompanyUser();
        $company->update(['accepted_ky_percentage' => 75]);

        $this->actingAs($viewer)->get(route('portal.companies.show', $company->slug))
            ->assertOk()
            ->assertSee('Accetta Kmoney 75%');
    }
}
