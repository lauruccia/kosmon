<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre /admin/mlm-report (Admin\MlmEarningsReportController, 2026-07-29):
 * report guadagni di sola lettura per l'admin, distinto dal FLUSSO di
 * approvazione delle liquidazioni (MlmPayoutController). Vedi anche
 * MlmPayoutServiceTest per la soglia minima di prelievo self-service.
 */
class MlmEarningsReportControllerTest extends TestCase
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
            'mlm_rank'             => 'basic',
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

    private function giveCommission(User $agent, int $amountEurCents, string $status = 'pending'): MlmCommission
    {
        // mlm_commission_runs.period_month e' UNIQUE: riusa il run del mese
        // corrente se gia' creato da una chiamata precedente nello stesso test.
        $periodMonth = now()->startOfMonth();
        $run = MlmCommissionRun::whereDate('period_month', $periodMonth->toDateString())->first();

        if (! $run) {
            $run = MlmCommissionRun::create([
                'period_month'     => $periodMonth->toDateString(),
                'idempotency_key'  => 'run_' . Str::random(10),
                'status'           => 'completed',
                'started_at'       => now(),
                'completed_at'     => now(),
            ]);
        }

        return MlmCommission::create([
            'mlm_commission_run_id' => $run->id,
            'agent_user_id'         => $agent->id,
            'type'                  => 'diretta',
            'source_client_id'      => $this->makeClient($agent)->id,
            'base_amount_eur_cents' => $amountEurCents * 5,
            'percentage'            => 20.0,
            'amount_eur_cents'      => $amountEurCents,
            'status'                => $status,
            'idempotency_key'       => 'commission_' . Str::random(10),
        ]);
    }

    private function giveBonus(User $agent, int $amountEurCents, string $status = 'pending'): MlmBonusPayout
    {
        $event = MlmBonusEvent::create([
            'basiq_user_id' => $this->makeAgent()->id,
            'triggered_at'  => now(),
            'status'        => 'processed',
            'processed_at'  => now(),
        ]);

        return MlmBonusPayout::create([
            'mlm_bonus_event_id'  => $event->id,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time'        => 'key',
            'amount_eur_cents'    => $amountEurCents,
            'week_ending'         => now()->toDateString(),
            'status'              => $status,
            'idempotency_key'     => 'bonus_' . Str::random(10),
        ]);
    }

    public function test_index_requires_backoffice_access(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAsWithSession($user)->get(route('admin.mlm.earnings.index'))->assertForbidden();
    }

    public function test_index_shows_earned_paid_and_outstanding_totals_per_agent(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->giveCommission($agent, 200_000, 'paid');
        $this->giveCommission($agent, 300_000, 'pending');
        $this->giveBonus($agent, 100_000, 'paid');
        $this->giveBonus($agent, 50_000, 'approved');

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.earnings.index'));

        $response->assertOk();
        // Maturato 6.500, pagato 3.000, da pagare 3.500 (EUR).
        $response->assertSee('6.500,00', false)
            ->assertSee('3.000,00', false)
            ->assertSee('3.500,00', false);
    }

    public function test_show_lists_the_single_agents_commission_and_bonus_lines(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->giveCommission($agent, 420_000, 'paid');
        $this->giveBonus($agent, 80_000, 'pending');

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.earnings.show', $agent));

        $response->assertOk()
            ->assertSee($agent->name)
            ->assertSee('4.200,00', false)
            ->assertSee('800,00', false);
    }

    public function test_show_404s_for_a_non_agent_user(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient($this->makeAgent());

        $this->actingAsWithSession($admin)->get(route('admin.mlm.earnings.show', $client))->assertNotFound();
    }

    public function test_export_csv_downloads_a_summary_row_per_agent(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $this->giveCommission($agent, 1_000, 'paid');

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.earnings.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
