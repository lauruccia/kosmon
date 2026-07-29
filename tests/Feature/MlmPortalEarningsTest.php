<?php

namespace Tests\Feature;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre /portale/mlm/guadagni (MlmPortalController::guadagni/guadagniExport,
 * 2026-07-29): report guadagni dell'agente stesso ("ogni agente deve poter
 * vedere i propri report", richiesta di Laura). Vedi anche
 * MlmEarningsReportControllerTest per l'equivalente admin.
 */
class MlmPortalEarningsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(): User
    {
        $user = User::create([
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
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
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

    public function test_guadagni_page_is_restricted_to_mlm_agents(): void
    {
        $notAnAgent = User::create([
            'name'                => 'Non Agente',
            'email'                => 'nonagente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
        ]);
        $notAnAgent->forceFill(['email_verified_at' => now()])->save();

        $this->actingAsWithSession($notAnAgent)->get(route('portal.mlm.earnings'))->assertForbidden();
    }

    public function test_guadagni_page_shows_the_agents_own_totals(): void
    {
        $agent = $this->makeAgent();
        $this->giveCommission($agent, 250_000, 'paid');
        $this->giveBonus($agent, 100_000, 'pending');

        $response = $this->actingAsWithSession($agent)->get(route('portal.mlm.earnings'));

        $response->assertOk()
            ->assertSee('3.500,00', false)
            ->assertSee('2.500,00', false)
            ->assertSee('1.000,00', false);
    }

    public function test_guadagni_export_downloads_a_csv(): void
    {
        $agent = $this->makeAgent();
        $this->giveCommission($agent, 1_200, 'pending');

        $response = $this->actingAsWithSession($agent)->get(route('portal.mlm.earnings.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
