<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MlmBonusPayout;
use App\Models\MlmWalletLedgerEntry;
use App\Models\User;
use App\Services\MlmWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre `mlm:cancel-direct-bonuses` (2026-08-14, disattivazione dei Bonus
 * Diretti KNM richiesta da Laura): annulla i Bonus Diretti gia' generati e
 * ancora pendenti e riporta alla Cassa Circuito il KY che era stato
 * accreditato nel cassetto kmoney.
 *
 * Il comando NON deve toccare:
 *  - i bonus di struttura e gli Extra Bonus (kind diverso da 'diretto');
 *  - i Bonus Diretti gia' 'approved'/'paid' (gia' in una liquidazione EUR).
 */
class CancelMlmDirectBonusesCommandTest extends TestCase
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
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_rank'            => 'basic',
            'mlm_activated_at'    => now(),
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

    private function agentBalance(User $agent): int
    {
        return (int) Account::where('owner_user_id', $agent->id)
            ->whereNull('parent_account_id')
            ->firstOrFail()
            ->available_balance;
    }

    /** Crea un payout gia' accreditato nel cassetto, come farebbe il flusso reale. */
    private function makeCreditedPayout(User $agent, int $amountCents, string $kind = 'diretto', string $status = 'pending'): MlmBonusPayout
    {
        $payout = MlmBonusPayout::create([
            'mlm_bonus_event_id'  => null,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time'        => null,
            'kind'                => $kind,
            'amount_eur_cents'    => $amountCents,
            'week_ending'         => now()->toDateString(),
            'status'              => $status,
            'idempotency_key'     => 'bonus_' . Str::random(10),
        ]);

        $this->wallet->creditFromBonusPayout($payout);

        return $payout;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $agent = $this->makeAgent();
        $this->makeCreditedPayout($agent, 20_000);

        $this->artisan('mlm:cancel-direct-bonuses')->assertSuccessful();

        $this->assertSame('pending', MlmBonusPayout::firstOrFail()->status);
        $this->assertSame(20_000, $this->agentBalance($agent->fresh()));
    }

    public function test_force_cancels_pending_direct_bonuses_and_reverses_the_ky(): void
    {
        $agent = $this->makeAgent();
        $this->makeCreditedPayout($agent, 20_000);
        $this->makeCreditedPayout($agent, 30_000);
        $this->makeCreditedPayout($agent, 40_000);

        $this->assertSame(90_000, $this->agentBalance($agent->fresh()));

        $this->artisan('mlm:cancel-direct-bonuses', ['--force' => true])->assertSuccessful();

        $this->assertSame(3, MlmBonusPayout::where('status', 'cancelled')->count());
        $this->assertSame(0, MlmBonusPayout::where('status', 'pending')->count());

        // Il KY e' tornato alla Cassa Circuito e il "prelevabile" e' a zero.
        $this->assertSame(0, $this->agentBalance($agent->fresh()));
        $this->assertSame(0, $this->wallet->withdrawableBalance($agent->fresh()));

        // Il contatore informativo "bonus" del cassetto si azzera anche lui:
        // le righe di storno hanno categoria 'bonus', non null.
        $this->assertSame(0, $this->wallet->categoryBreakdown($agent->fresh())['bonus']);
    }

    public function test_other_bonus_kinds_and_already_liquidated_ones_are_untouched(): void
    {
        $agent = $this->makeAgent();
        $structure = $this->makeCreditedPayout($agent, 11_000, 'senior');   // bonus di struttura
        $extra = $this->makeCreditedPayout($agent, 30_000, 'extra');        // Extra Bonus di grado
        $approved = $this->makeCreditedPayout($agent, 20_000, 'diretto', 'approved');
        $pending = $this->makeCreditedPayout($agent, 40_000, 'diretto');

        $this->artisan('mlm:cancel-direct-bonuses', ['--force' => true])->assertSuccessful();

        $this->assertSame('pending', $structure->fresh()->status);
        $this->assertSame('pending', $extra->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status, 'Un Bonus Diretto gia\' in liquidazione non va toccato.');
        $this->assertSame('cancelled', $pending->fresh()->status);

        // Stornato solo il pendente: 101.000 accreditati - 40.000 stornati.
        $this->assertSame(61_000, $this->agentBalance($agent->fresh()));
    }

    public function test_running_twice_does_not_reverse_the_ky_twice(): void
    {
        $agent = $this->makeAgent();
        $this->makeCreditedPayout($agent, 20_000);

        $this->artisan('mlm:cancel-direct-bonuses', ['--force' => true])->assertSuccessful();
        $this->artisan('mlm:cancel-direct-bonuses', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, $this->agentBalance($agent->fresh()));
        $this->assertSame(
            1,
            MlmWalletLedgerEntry::where('source_type', 'bonus_payout_reversal')->count(),
            'Lo storno deve essere idempotente: una sola riga negativa nel cassetto.'
        );
    }

    public function test_nothing_to_cancel_is_not_an_error(): void
    {
        $this->artisan('mlm:cancel-direct-bonuses', ['--force' => true])
            ->expectsOutputToContain('Nessun Bonus Diretto pendente')
            ->assertSuccessful();
    }
}
