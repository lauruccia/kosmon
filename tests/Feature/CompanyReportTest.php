<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CompanyReport;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Services\CompanyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature "segnalazione azienda" (richiesta di Laura, 29/07/2026 — vedi
 * CompanyReportService). Copre: risoluzione dell'agente (incluso il
 * fallback sulla radice di sistema per i clienti diretti KNM), erogazione
 * idempotente del bonus alla firma del contratto, e il rifiuto senza bonus.
 */
class CompanyReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name'                => 'Super Admin',
            'email'               => 'superadmin-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** Cliente privato con conto attivo. */
    private function makeClient(?int $agentId = null): User
    {
        $client = User::create([
            'name'                => 'Cliente',
            'email'               => 'cliente-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => false,
            'mlm_client_agent_id' => $agentId,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'      => $client->id,
            'owner_type'         => 'private',
            'type'               => 'primary',
            'account_name'       => 'Conto personale Cliente',
            'currency_code'      => 'KY',
            'status'             => 'active',
            'available_balance'  => 0,
        ]);

        return $client->refresh();
    }

    /** Agente KNM con un proprio conto attivo. */
    private function makeAgent(string $name = 'Agente'): User
    {
        $agent = User::create([
            'name'                => $name,
            'email'               => 'agente-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => false,
            'mlm_role'            => 'agente',
        ]);
        $agent->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'      => $agent->id,
            'owner_type'         => 'private',
            'type'               => 'primary',
            'account_name'       => 'Conto Agente',
            'currency_code'      => 'KY',
            'status'             => 'active',
            'available_balance'  => 0,
        ]);

        return $agent->refresh();
    }

    private function accountBalance(User $user): int
    {
        return (int) Account::where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance;
    }

    // ── Risoluzione agente ──────────────────────────────────────────────────

    public function test_submit_resolves_and_stores_the_client_assigned_agent(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
            'company_city' => 'Cagliari',
        ]);

        $this->assertSame($agent->id, $report->agent_user_id);
        $this->assertSame($client->id, $report->user_id);
        $this->assertTrue($report->isPending());
    }

    public function test_submit_falls_back_to_system_root_agent_when_client_has_no_agent(): void
    {
        $this->makeSuperAdmin();
        $rootAgent = $this->makeAgent('Radice di Sistema');
        SystemSetting::mlmSettings()->forceFill(['mlm_root_agent_id' => $rootAgent->id])->save();

        $client = $this->makeClient(null); // "cliente diretto KNM"

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Pizzeria Sole',
        ]);

        $this->assertSame($rootAgent->id, $report->agent_user_id);
    }

    public function test_submit_leaves_agent_null_when_no_agent_and_no_system_root(): void
    {
        $this->makeSuperAdmin();
        $client = $this->makeClient(null);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Senza Agente Srl',
        ]);

        $this->assertNull($report->agent_user_id);
    }

    // ── Contratto firmato: bonus idempotente ────────────────────────────────

    public function test_mark_contract_signed_credits_the_attivita_bonus_amount_from_system_account(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        $expectedAmount = (int) SystemSetting::userLimitDefaults()->referral_bonus_attivita_amount;

        app(CompanyReportService::class)->markContractSigned($report, $agent, 'Firmato in loco');

        $report->refresh();
        $this->assertTrue($report->isContractSigned());
        $this->assertSame($expectedAmount, $this->accountBalance($client));

        $transfer = Transfer::where('idempotency_key', "company_report_bonus_{$report->id}")->firstOrFail();
        $systemAccount = Account::systemAccount();
        $this->assertSame($systemAccount->id, $transfer->from_account_id);
        $this->assertSame($expectedAmount, $transfer->amount);
        $this->assertSame($transfer->id, $report->bonus_transfer_id);

        // Il conto dell'agente NON viene toccato: il bonus è a carico del conto madre.
        $this->assertSame(0, $this->accountBalance($agent));
    }

    public function test_mark_contract_signed_is_idempotent_on_double_call(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        $service = app(CompanyReportService::class);
        $service->markContractSigned($report, $agent);
        $service->markContractSigned($report->fresh(), $agent); // replay / doppio click

        $expectedAmount = (int) SystemSetting::userLimitDefaults()->referral_bonus_attivita_amount;

        $this->assertSame($expectedAmount, $this->accountBalance($client)); // non raddoppiato
        $this->assertSame(1, Transfer::where('idempotency_key', "company_report_bonus_{$report->id}")->count());
    }

    public function test_mark_contract_signed_skips_bonus_but_still_closes_when_amount_disabled(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        SystemSetting::userLimitDefaults()->forceFill(['referral_bonus_attivita_amount' => 0])->save();

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        app(CompanyReportService::class)->markContractSigned($report, $agent);

        $report->refresh();
        $this->assertTrue($report->isContractSigned());
        $this->assertNull($report->bonus_transfer_id);
        $this->assertSame(0, $this->accountBalance($client));
    }

    // ── Rifiuto: richiede motivazione, nessun bonus ─────────────────────────

    public function test_mark_rejected_requires_a_reason_via_the_controller(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        $response = $this->actingAs($agent)->post(
            route('portal.mlm.company-reports.reject', $report),
            ['agent_notes' => '']
        );

        $response->assertSessionHasErrors('agent_notes');
        $this->assertTrue($report->fresh()->isPending());
    }

    public function test_mark_rejected_closes_without_any_bonus(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        app(CompanyReportService::class)->markRejected($report, $agent, 'Azienda non interessata');

        $report->refresh();
        $this->assertTrue($report->isRejected());
        $this->assertSame('Azienda non interessata', $report->agent_notes);
        $this->assertNull($report->bonus_transfer_id);
        $this->assertSame(0, $this->accountBalance($client));
        $this->assertSame(0, Transfer::where('idempotency_key', "company_report_bonus_{$report->id}")->count());
    }

    public function test_mark_rejected_is_a_noop_when_already_processed(): void
    {
        $this->makeSuperAdmin();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent->id);

        $report = app(CompanyReportService::class)->submit($client, [
            'company_name' => 'Bar Centrale',
        ]);

        $service = app(CompanyReportService::class);
        $service->markContractSigned($report, $agent);
        $service->markRejected($report->fresh(), $agent, 'Troppo tardi'); // non deve sovrascrivere

        $report->refresh();
        $this->assertTrue($report->isContractSigned()); // resta come firmato, non rifiutato
    }
}
