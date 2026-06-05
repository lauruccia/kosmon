<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\KycDocument;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un utente aziendale che NON ha completato l'onboarding:
     * niente sector/description, nessun KYC documento, status pending.
     */
    private function makeNewCompanyUser(): array
    {
        $slug = 'onb-' . Str::random(4);

        $company = Company::create([
            'name'          => 'Onboarding Co',
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'pending',
            'currency_code' => 'KY',
            // sector e description volutamente omessi
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Onboarding User',
            'email'               => 'onbuser-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return [$user, $company];
    }

    // ── step0 ─────────────────────────────────────────────────────────────────

    public function test_onboarding_requires_authentication(): void
    {
        $this->get(route('onboarding.step0'))
            ->assertRedirect(route('login'));
    }

    public function test_new_company_user_can_view_welcome_step(): void
    {
        [$user] = $this->makeNewCompanyUser();

        $this->actingAs($user)
            ->get(route('onboarding.step0'))
            ->assertOk()
            ->assertSee('Benvenuto', false);
    }

    // ── step1 ─────────────────────────────────────────────────────────────────

    public function test_new_company_user_can_view_profile_step(): void
    {
        [$user] = $this->makeNewCompanyUser();

        $this->actingAs($user)
            ->get(route('onboarding.step1'))
            ->assertOk();
    }

    public function test_user_can_save_company_profile(): void
    {
        [$user, $company] = $this->makeNewCompanyUser();

        // Sector must exist in DB for Rule::in() to pass
        Sector::create(['name' => 'informatica', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('onboarding.step1.save'), [
                'sector'      => 'informatica',
                'description' => 'Azienda specializzata in soluzioni software innovative.',
                'city'        => 'Milano',
            ])
            ->assertRedirect();

        $company->refresh();
        $this->assertSame('informatica', $company->sector);
        $this->assertNotEmpty($company->description);
    }

    public function test_save_step1_validates_required_fields(): void
    {
        [$user] = $this->makeNewCompanyUser();

        $this->actingAs($user)
            ->post(route('onboarding.step1.save'), [])
            ->assertSessionHasErrors(['sector', 'description']);
    }

    // ── step3 (attesa) ────────────────────────────────────────────────────────

    public function test_user_can_view_waiting_step(): void
    {
        [$user, $company] = $this->makeNewCompanyUser();

        // Aggiorna company per avere documenti caricati (simula step2 completato)
        $company->update([
            'sector'      => 'informatica',
            'description' => 'Test',
        ]);

        // step3 controller checks kycDocuments()->count() > 0
        KycDocument::create([
            'company_id'          => $company->id,
            'uploaded_by_user_id' => $user->id,
            'type'                => 'visura',
            'file_path'           => 'kyc/test.pdf',
            'original_name'       => 'test.pdf',
            'mime_type'           => 'application/pdf',
            'file_size'           => 1024,
            'status'              => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('onboarding.step3'))
            ->assertOk();
    }
}
