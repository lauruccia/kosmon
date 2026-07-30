<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionRun;
use App\Models\MlmWalletLedgerEntry;
use App\Models\User;
use App\Services\MlmWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cassetto kmoney (2026-07-30, richiesta di Laura): compensi diretti,
 * indiretti, estesi e bonus vengono accreditati subito in KY sul conto
 * dell'agente, spendibili da subito, ma restano prelevabili/convertibili
 * in € finche' non vengono spesi. Vedi App\Services\MlmWalletService.
 */
class MlmWalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmWalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = new MlmWalletService();
        $this->makeSuperAdmin();
    }

    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name'                => 'Super Admin',
            'email'               => 'superadmin-' . Str::random(8) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'is_active'           => true,
            'is_super_admin'      => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function makeAgent(): User
    {
        $agent = User::create([
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
        $agent->forceFill(['email_verified_at' => now()])->save();

        Account::create([
            'owner_user_id'     => $agent->id,
            'owner_type'        => 'private',
            'type'              => 'primary',
            'account_name'      => 'Conto personale ' . $agent->name,
            'currency_code'     => 'KY',
            'status'            => 'active',
            'available_balance' => 0,
        ]);

        return $agent->refresh();
    }

    private function agentAccount(User $agent): Account
    {
        return Account::where('owner_user_id', $agent->id)->whereNull('parent_account_id')->firstOrFail();
    }

    private function makeCommission(User $agent, int $amountCents, string $type = 'diretta', ?int $level = null): MlmCommission
    {
        // firstOrCreate per period_month: mlm_commission_runs.period_month e'
        // UNIQUE, quindi due chiamate a makeCommission() nello stesso test
        // (stesso mese) devono riusare lo stesso run invece di crearne un
        // secondo e violare il vincolo.
        $run = MlmCommissionRun::firstOrCreate(
            ['period_month' => now()->startOfMonth()->toDateString()],
            [
                'idempotency_key' => 'run_' . Str::random(10),
                'status'          => 'completed',
                'started_at'      => now(),
                'completed_at'    => now(),
            ],
        );

        $client = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'is_active'            => true,
            'mlm_role'             => 'cliente',
        ]);

        return MlmCommission::create([
            'mlm_commission_run_id' => $run->id,
            'agent_user_id'         => $agent->id,
            'type'                  => $type,
            'source_client_id'      => $client->id,
            'level'                 => $level,
            'base_amount_eur_cents' => $amountCents * 5,
            'percentage'            => 20.0,
            'amount_eur_cents'      => $amountCents,
            'status'                => 'pending',
            'idempotency_key'       => 'commission_' . Str::random(10),
        ]);
    }

    private function makeBonusPayout(User $agent, int $amountCents, string $kind = 'diretto'): MlmBonusPayout
    {
        return MlmBonusPayout::create([
            'mlm_bonus_event_id'  => null,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time'        => 'key',
            'kind'                => $kind,
            'amount_eur_cents'    => $amountCents,
            'week_ending'         => now()->toDateString(),
            'status'              => 'pending',
            'idempotency_key'     => 'bonus_' . Str::random(10),
        ]);
    }

    public function test_credit_from_commission_moves_ky_from_system_account_and_logs_the_ledger(): void
    {
        $agent = $this->makeAgent();
        $commission = $this->makeCommission($agent, 2_000, 'diretta');

        $this->wallet->creditFromCommission($commission);

        $this->assertSame(2_000, (int) $this->agentAccount($agent)->fresh()->available_balance);
        $this->assertSame(2_000, $this->wallet->withdrawableBalance($agent->fresh()));

        $entry = MlmWalletLedgerEntry::where('agent_user_id', $agent->id)->firstOrFail();
        $this->assertSame('diretta', $entry->category);
        $this->assertSame(2_000, $entry->amount_cents);
        $this->assertSame('commission', $entry->source_type);
        $this->assertNotNull($entry->transfer_id);
    }

    public function test_credit_from_commission_is_idempotent(): void
    {
        $agent = $this->makeAgent();
        $commission = $this->makeCommission($agent, 2_000, 'diretta');

        $this->wallet->creditFromCommission($commission);
        $this->wallet->creditFromCommission($commission);

        $this->assertSame(2_000, (int) $this->agentAccount($agent)->fresh()->available_balance);
        $this->assertSame(1, MlmWalletLedgerEntry::where('agent_user_id', $agent->id)->count());
    }

    public function test_indirect_commission_beyond_level_5_is_categorized_as_estesa(): void
    {
        $agent = $this->makeAgent();
        $level5 = $this->makeCommission($agent, 1_000, 'indiretta', 5);
        $level6 = $this->makeCommission($agent, 500, 'indiretta', 6);

        $this->wallet->creditFromCommission($level5);
        $this->wallet->creditFromCommission($level6);

        $breakdown = $this->wallet->categoryBreakdown($agent->fresh());
        $this->assertSame(1_000, $breakdown['indiretta']);
        $this->assertSame(500, $breakdown['estesa']);
    }

    public function test_credit_from_bonus_payout_is_always_categorized_as_bonus(): void
    {
        $agent = $this->makeAgent();
        $this->wallet->creditFromBonusPayout($this->makeBonusPayout($agent, 6_000, 'struttura'));
        $this->wallet->creditFromBonusPayout($this->makeBonusPayout($agent, 20_000, 'diretto'));
        $this->wallet->creditFromBonusPayout($this->makeBonusPayout($agent, 30_000, 'extra'));

        $breakdown = $this->wallet->categoryBreakdown($agent->fresh());
        $this->assertSame(56_000, $breakdown['bonus']);
        $this->assertSame(56_000, $this->wallet->withdrawableBalance($agent->fresh()));
    }

    public function test_withdrawable_balance_is_capped_by_what_is_actually_left_on_the_account(): void
    {
        $agent = $this->makeAgent();
        $this->wallet->creditFromCommission($this->makeCommission($agent, 5_000, 'diretta'));

        // L'agente spende parte del cassetto in negozio (o altrove): il
        // saldo del conto scende, ma la riga ledger resta la stessa —
        // il prelevabile deve scendere di conseguenza (mai superare il
        // saldo KY realmente disponibile).
        $account = $this->agentAccount($agent);
        $account->forceFill(['available_balance' => 1_200])->save();

        $this->assertSame(1_200, $this->wallet->withdrawableBalance($agent->fresh()));
    }

    public function test_credit_does_not_throw_when_agent_has_no_personal_account(): void
    {
        $agentWithoutAccount = User::create([
            'name'                => 'Senza Conto',
            'email'                => 'senzaconto-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'is_active'            => true,
            'mlm_role'             => 'agente',
        ]);

        $client = User::create([
            'name'                => 'Cliente',
            'email'                => 'cliente-x-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'is_active'            => true,
        ]);
        $run = MlmCommissionRun::create([
            'period_month'    => now()->startOfMonth()->toDateString(),
            'idempotency_key' => 'run_' . Str::random(10),
            'status'          => 'completed',
            'started_at'      => now(),
            'completed_at'    => now(),
        ]);
        $commission = MlmCommission::create([
            'mlm_commission_run_id' => $run->id,
            'agent_user_id'         => $agentWithoutAccount->id,
            'type'                  => 'diretta',
            'source_client_id'      => $client->id,
            'base_amount_eur_cents' => 10_000,
            'percentage'            => 20.0,
            'amount_eur_cents'      => 2_000,
            'status'                => 'pending',
            'idempotency_key'       => 'commission_' . Str::random(10),
        ]);

        $this->wallet->creditFromCommission($commission);

        $this->assertSame(0, MlmWalletLedgerEntry::count());
        $this->assertSame(0, $this->wallet->withdrawableBalance($agentWithoutAccount->fresh()));
    }

    public function test_reserve_for_payout_moves_ky_back_to_the_system_account_and_reduces_withdrawable_balance(): void
    {
        $agent = $this->makeAgent();
        $this->wallet->creditFromCommission($this->makeCommission($agent, 5_000, 'diretta'));

        $this->wallet->reserveForPayout($agent->fresh(), 2_000, 'test_reserve_key', 'Riserva di test');

        $this->assertSame(3_000, (int) $this->agentAccount($agent)->fresh()->available_balance);
        $this->assertSame(3_000, $this->wallet->withdrawableBalance($agent->fresh()));
    }

    public function test_reserve_for_payout_is_idempotent_on_the_key(): void
    {
        $agent = $this->makeAgent();
        $this->wallet->creditFromCommission($this->makeCommission($agent, 5_000, 'diretta'));

        $this->wallet->reserveForPayout($agent->fresh(), 2_000, 'test_reserve_key', 'Riserva di test');
        $this->wallet->reserveForPayout($agent->fresh(), 2_000, 'test_reserve_key', 'Riserva di test');

        $this->assertSame(3_000, (int) $this->agentAccount($agent)->fresh()->available_balance);
    }

    public function test_release_reservation_returns_ky_to_the_agent(): void
    {
        $agent = $this->makeAgent();
        $this->wallet->creditFromCommission($this->makeCommission($agent, 5_000, 'diretta'));
        $this->wallet->reserveForPayout($agent->fresh(), 2_000, 'test_reserve_key', 'Riserva di test');

        $this->wallet->releaseReservation($agent->fresh(), 2_000, 'test_release_key', 'Rilascio di test');

        $this->assertSame(5_000, (int) $this->agentAccount($agent)->fresh()->available_balance);
        $this->assertSame(5_000, $this->wallet->withdrawableBalance($agent->fresh()));
    }
}
