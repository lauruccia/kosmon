<?php

namespace Tests\Feature;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\MlmPayout;
use App\Models\User;
use App\Services\MlmPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre l'aggregazione di commissioni e bonus in liquidazioni EUR
 * (mlm_payouts) e il ciclo di stato pending -> approved -> paid
 * (oppure -> rejected), incluso il prelievo "tutto il maturato" richiesto
 * dal portale agente.
 *
 * Vedi app/Services/MlmPayoutService.php.
 */
class MlmPayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmPayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MlmPayoutService();
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

    private function makeAdmin(): User
    {
        return User::create([
            'name'                => 'Admin',
            'email'                => 'admin-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => true,
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

    private function givePendingCommission(User $agent, int $amountEurCents, ?\Illuminate\Support\Carbon $month = null): MlmCommission
    {
        $month ??= now();
        $periodMonth = $month->copy()->startOfMonth();

        // mlm_commission_runs.period_month e' UNIQUE: riusa il run del mese se
        // gia' creato da una chiamata precedente nello stesso test (whereDate,
        // non un confronto diretto: vedi la nota in MlmPayoutService su questo
        // stesso pattern per le colonne 'date'-cast).
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
            'status'                => 'pending',
            'idempotency_key'       => 'commission_' . Str::random(10),
        ]);
    }

    private function givePendingBonus(User $agent, int $amountEurCents, ?\Illuminate\Support\Carbon $weekEnding = null): MlmBonusPayout
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
            'week_ending'         => ($weekEnding ?? now())->toDateString(),
            'status'              => 'pending',
            'idempotency_key'     => 'bonus_' . Str::random(10),
        ]);
    }

    public function test_generate_for_month_aggregates_commissions_and_bonuses_for_the_agent(): void
    {
        $agent = $this->makeAgent();
        $this->givePendingCommission($agent, 2_000);
        $this->givePendingBonus($agent, 3_000);

        $payouts = $this->service->generateForMonth(now());

        $this->assertCount(1, $payouts);
        $payout = $payouts->first();
        $this->assertSame($agent->id, $payout->agent_user_id);
        $this->assertSame(2_000, $payout->commissions_total_eur_cents);
        $this->assertSame(3_000, $payout->bonus_total_eur_cents);
        $this->assertSame(5_000, $payout->total_eur_cents);
        $this->assertSame('pending', $payout->status);
    }

    public function test_generate_for_month_is_idempotent_and_only_attaches_free_rows(): void
    {
        $agent = $this->makeAgent();
        $this->givePendingCommission($agent, 2_000);

        $this->service->generateForMonth(now());
        $this->assertSame(1, MlmPayout::count());

        // Una seconda commissione libera dello stesso mese deve agganciarsi allo STESSO payout pending.
        $this->givePendingCommission($agent, 1_000);
        $this->service->generateForMonth(now());

        $this->assertSame(1, MlmPayout::count());
        $this->assertSame(3_000, MlmPayout::first()->commissions_total_eur_cents);
    }

    public function test_approve_transitions_payout_and_linked_rows(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();

        $approved = $this->service->approve($payout, $admin);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($admin->id, $approved->approved_by_user_id);
        $this->assertSame('approved', MlmCommission::where('mlm_payout_id', $payout->id)->first()->status);
    }

    public function test_approve_rejects_a_non_pending_payout(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();
        $this->service->approve($payout, $admin);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($payout->fresh(), $admin);
    }

    public function test_mark_paid_transitions_approved_payout_and_linked_rows(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();
        $this->service->approve($payout, $admin);

        $paid = $this->service->markPaid($payout->fresh(), $admin, 'BONIFICO-123');

        $this->assertSame('paid', $paid->status);
        $this->assertSame('BONIFICO-123', $paid->payment_reference);
        $this->assertSame('paid', MlmCommission::where('mlm_payout_id', $payout->id)->first()->status);
    }

    public function test_mark_paid_rejects_a_non_approved_payout(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();

        $this->expectException(\RuntimeException::class);
        $this->service->markPaid($payout, $admin, 'BONIFICO-999');
    }

    public function test_reject_unlinks_rows_back_to_pending(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();

        $rejected = $this->service->reject($payout, $admin, 'errore di calcolo');

        $this->assertSame('rejected', $rejected->status);
        $commission = MlmCommission::where('agent_user_id', $agent->id)->first();
        $this->assertNull($commission->mlm_payout_id);
        $this->assertSame('pending', $commission->status);
    }

    public function test_pending_withdrawable_cents_sums_unlinked_commissions_and_bonuses(): void
    {
        $agent = $this->makeAgent();
        $this->givePendingCommission($agent, 2_000);
        $this->givePendingBonus($agent, 3_000);

        $this->assertSame(5_000, $this->service->pendingWithdrawableCents($agent));
    }

    public function test_has_open_payout_reflects_pending_and_approved_states(): void
    {
        $agent = $this->makeAgent();
        $admin = $this->makeAdmin();
        $this->assertFalse($this->service->hasOpenPayout($agent));

        $this->givePendingCommission($agent, 2_000);
        $payout = $this->service->generateForMonth(now())->first();
        $this->assertTrue($this->service->hasOpenPayout($agent));

        $this->service->markPaid($this->service->approve($payout, $admin), $admin, 'REF-1');
        $this->assertFalse($this->service->hasOpenPayout($agent->fresh()));
    }

    public function test_request_withdrawal_aggregates_all_free_maturato_into_one_payout(): void
    {
        $agent = $this->makeAgent();
        $this->givePendingCommission($agent, 2_000, now()->subMonth());
        $this->givePendingBonus($agent, 1_500, now()->subWeek());

        $payout = $this->service->requestWithdrawal($agent);

        $this->assertSame(3_500, $payout->total_eur_cents);
        $this->assertNotNull($payout->requested_at);
        $this->assertSame('pending', $payout->status);
    }

    public function test_request_withdrawal_fails_when_a_request_is_already_open(): void
    {
        $agent = $this->makeAgent();
        $this->givePendingCommission($agent, 2_000);
        $this->service->requestWithdrawal($agent);

        $this->givePendingCommission($agent, 1_000);

        $this->expectException(\RuntimeException::class);
        $this->service->requestWithdrawal($agent->fresh());
    }

    public function test_request_withdrawal_fails_with_nothing_to_withdraw(): void
    {
        $agent = $this->makeAgent();

        $this->expectException(\RuntimeException::class);
        $this->service->requestWithdrawal($agent);
    }
}
