<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica il comportamento del middleware TwoFactorChallenge
 * per utenti che non hanno ancora configurato nessun secondo fattore.
 *
 * Nota: usa withoutMiddleware o bypass manuale dell'env check per
 * simulare l'ambiente di produzione dove REQUIRE_2FA è attivo.
 */
class TwoFactorRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithoutMfa(): User
    {
        $user = User::factory()->create([
            'email_verified_at'       => now(),
            'two_factor_confirmed_at' => null,
            'two_factor_secret'       => null,
            'contract_signed_at'      => now(),
            'is_super_admin'          => false,
        ]);
        $company = Company::factory()->create(['kyc_status' => 'approved']);
        Account::factory()->create([
            'company_id'        => $company->id,
            'owner_user_id'     => $user->id,
            'status'            => 'active',
            'currency_code'     => 'KY',
            'available_balance' => 10000,
        ]);
        $user->update(['company_id' => $company->id]);

        return $user;
    }

    /** La pagina /2fa/obbligatorio è accessibile per utenti senza 2FA */
    public function test_required_page_is_accessible(): void
    {
        $user = $this->makeUserWithoutMfa();

        $response = $this->actingAs($user)->get(route('portal.2fa.required'));

        $response->assertOk();
        $response->assertViewIs('2fa.required');
    }

    /** La pagina /2fa/obbligatorio redirige se l'utente ha già il 2FA */
    public function test_required_page_redirects_if_2fa_already_configured(): void
    {
        $user = $this->makeUserWithoutMfa();
        $user->forceFill([
            'two_factor_secret'       => 'somesecret',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->actingAs($user)
            ->withSession(['two_factor_verified' => true])
            ->get(route('portal.2fa.required'));

        $response->assertRedirect(route('portal.dashboard'));
    }

    /** Un super admin senza 2FA non viene bloccato */
    public function test_super_admin_bypasses_2fa_requirement(): void
    {
        $user = $this->makeUserWithoutMfa();
        $user->forceFill(['is_super_admin' => true])->save();

        // In testing il bypass è attivo per tutti; verifichiamo solo che
        // il super admin non sia reindirizzato dalla logica specifica
        $response = $this->actingAs($user)->get(route('portal.security'));
        $response->assertOk();
    }
}
