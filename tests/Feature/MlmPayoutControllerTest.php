<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MlmCommission;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il pannello admin /admin/mlm-payouts (MlmPayoutController), in
 * particolare l'azione "Calcola commissioni ora" introdotta il 2026-07-13:
 * senza cron ne' terminale su un sito di test (kosmopay.it), le commissioni
 * dirette/indirette (normalmente calcolate il 1° del mese alle 02:00 da
 * mlm:calculate-commissions) non venivano mai generate, lasciando
 * /admin/mlm-payouts vuota anche con bonus gia' maturati. Stesso pattern di
 * MlmSettingsController::recalculateNow(), vedi MlmSettingsControllerTest.
 */
class MlmPayoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'                => 'admin-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Utente normale: supera auth/verified/onboarding/contract ma NON e' backoffice. */
    private function makeRegularUser(): User
    {
        $slug = 'reg-' . Str::random(5);

        $company = Company::create([
            'name'          => 'Reg Co ' . Str::random(4),
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
            'name'                => 'Reg User',
            'email'               => 'reg-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        return $user;
    }

    private function makeAgent(): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => 'start',
            'mlm_activated_at'     => now(),
        ]);
    }

    private function makeClient(User $agent): User
    {
        return User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent->id,
        ]);
    }

    public function test_calculate_commissions_requires_backoffice_access(): void
    {
        $user = $this->makeRegularUser();

        $response = $this->actingAsWithSession($user)->post(route('admin.mlm.payouts.calculate-commissions'), [
            'month' => now()->format('Y-m'),
        ]);

        $response->assertForbidden();
    }

    public function test_calculate_commissions_now_creates_direct_commissions_without_cron(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        // >= 48 punti attivi (soglia 20% nella tabella percentuali dirette).
        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $client->id,
            'source_type'    => 'registration',
            'points'         => 50,
            'valid_from'     => now()->startOfMonth()->subDay(),
            'valid_until'    => now()->addMonth(),
        ]);

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id'           => $client->id,
            'direct_agent_id'          => $agent->id,
            'monthly_amount_eur_cents' => 10_000,
            'valid_from'               => now()->startOfMonth()->toDateString(),
            'valid_until'              => now()->addMonths(11)->toDateString(),
        ]);

        $this->assertSame(0, MlmCommission::count(), 'Nessuna commissione deve esistere prima del calcolo manuale (niente cron in questo ambiente).');

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.payouts.calculate-commissions'), [
            'month' => now()->format('Y-m'),
        ]);

        $response->assertRedirect(route('admin.mlm.payouts.index'));

        $commission = MlmCommission::where('agent_user_id', $agent->id)->where('type', 'diretta')->first();
        $this->assertNotNull($commission, 'Il comando mlm:calculate-commissions deve essere stato eseguito sincronamente.');
        $this->assertSame(2_000, $commission->amount_eur_cents);

        $this->assertDatabaseHas('audit_logs', [
            'event'        => 'admin.mlm.manual_calculate_commissions',
            'auditable_id' => $admin->id,
        ]);
    }

    public function test_calculate_commissions_then_generate_makes_the_payout_visible(): void
    {
        // Copre il flusso completo che mancava a Laura: prima le commissioni
        // erano 0 (nessun cron), poi anche generando le liquidazioni la
        // pagina restava vuota perche' non c'era nulla da aggregare.
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $client->id,
            'source_type'    => 'registration',
            'points'         => 50,
            'valid_from'     => now()->startOfMonth()->subDay(),
            'valid_until'    => now()->addMonth(),
        ]);

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id'           => $client->id,
            'direct_agent_id'          => $agent->id,
            'monthly_amount_eur_cents' => 10_000,
            'valid_from'               => now()->startOfMonth()->toDateString(),
            'valid_until'              => now()->addMonths(11)->toDateString(),
        ]);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.payouts.calculate-commissions'), [
            'month' => now()->format('Y-m'),
        ]);

        $this->assertSame(0, MlmPayout::count(), 'Le commissioni esistono ma nessuna liquidazione e\' stata ancora generata.');

        $this->actingAsWithSession($admin)->post(route('admin.mlm.payouts.generate'), [
            'month' => now()->format('Y-m'),
        ]);

        $payout = MlmPayout::where('agent_user_id', $agent->id)->first();
        $this->assertNotNull($payout);
        $this->assertSame(2_000, $payout->commissions_total_eur_cents);

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.payouts.index'));
        $response->assertOk();
        $response->assertSee($agent->name);
    }

    public function test_index_can_be_filtered_by_agent_name_or_email(): void
    {
        // Su kosmopay.it la generazione in blocco crea decine di liquidazioni
        // (una per agente): senza ricerca la pagina diventa inutilizzabile.
        $admin = $this->makeAdmin();

        $wanted = $this->makeAgent();
        $wanted->forceFill(['name' => 'Giacomo Gallo'])->save();
        $other = $this->makeAgent();
        $other->forceFill(['name' => 'Antonio Gentile'])->save();

        MlmPayout::create([
            'agent_user_id' => $wanted->id,
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
            'status' => 'pending',
            'commissions_total_eur_cents' => 0,
            'bonus_total_eur_cents' => 9_00,
            'total_eur_cents' => 9_00,
        ]);
        MlmPayout::create([
            'agent_user_id' => $other->id,
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
            'status' => 'pending',
            'commissions_total_eur_cents' => 0,
            'bonus_total_eur_cents' => 9_00,
            'total_eur_cents' => 9_00,
        ]);

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.payouts.index', ['q' => 'Giacomo']));

        $response->assertOk();
        $response->assertSee('Giacomo Gallo');
        $response->assertDontSee('Antonio Gentile');
    }

    public function test_index_search_composes_with_status_filter(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $agent->forceFill(['name' => 'Giacomo Gallo'])->save();

        $paid = MlmPayout::create([
            'agent_user_id' => $agent->id,
            'period_from' => now()->startOfMonth(),
            'period_to' => now()->endOfMonth(),
            'status' => 'paid',
            'commissions_total_eur_cents' => 0,
            'bonus_total_eur_cents' => 9_00,
            'total_eur_cents' => 9_00,
        ]);

        // Cerca "Giacomo" ma filtra per stato "pending": la liquidazione
        // esistente e' 'paid', quindi non deve comparire.
        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.payouts.index', [
            'q' => 'Giacomo',
            'status' => 'pending',
        ]));

        $response->assertOk();
        $response->assertSee('Nessuna liquidazione trovata.');
    }
}
