<?php

namespace Tests\Feature;

use App\Models\MlmBonusPayout;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il comando `mlm:calculate-weekly-bonuses` end-to-end: verifica che
 * collega correttamente i tre flussi (cascata struttura, bonus diretti,
 * extra bonus) dietro il comando Artisan schedulato ogni mercoledi' (vedi
 * routes/console.php e MLM_PROPOSAL.md §9). La logica di dettaglio di
 * ciascun flusso e' gia' coperta da MlmBonusServiceTest e
 * MlmAwardServiceTest: qui si verifica solo il cablaggio del comando.
 */
class CalculateMlmWeeklyBonusesCommandTest extends TestCase
{
    use RefreshDatabase;

    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
    }

    private function makeAgent(string $rank = 'start'): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => $rank,
            'mlm_activated_at'     => now(),
        ]);
    }

    private function givePoints(User $agent, int $points): void
    {
        $client = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent->id,
        ]);

        MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $client->id,
            'source_type'    => 'registration',
            'points'         => $points,
            'valid_from'     => now()->subMonths(2)->toDateString(),
            'valid_until'    => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_command_processes_pending_basiq_cascade_and_direct_bonuses_together(): void
    {
        $senior = $this->makeAgent('senior');
        $basiqChild = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiqChild, $senior);

        // Rilevamento notturno gia' avvenuto: evento BasiQ pending in attesa
        // del job settimanale.
        app(\App\Services\MlmBonusService::class)->recordBasiqEvent($basiqChild);

        // Un secondo agente ha raggiunto la soglia dei Bonus Diretti KNM ma
        // non e' ancora stato premiato (nessun evento BasiQ coinvolto).
        $directAgent = $this->makeAgent();
        $this->givePoints($directAgent, 6);

        $this->artisan('mlm:calculate-weekly-bonuses')->assertSuccessful();

        $this->assertSame(
            11_000,
            (int) MlmBonusPayout::where('beneficiary_user_id', $senior->id)->sum('amount_eur_cents'),
            'La cascata di struttura deve essere calcolata dal comando settimanale.'
        );

        $this->assertSame(
            2,
            MlmBonusPayout::where('beneficiary_user_id', $directAgent->id)->where('kind', 'diretto')->count(),
            'I Bonus Diretti (soglie 4 e 6) devono essere valutati per ogni agente dal comando settimanale.'
        );
    }

    public function test_command_is_idempotent_when_run_twice_in_a_row(): void
    {
        $senior = $this->makeAgent('senior');
        $basiqChild = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiqChild, $senior);
        app(\App\Services\MlmBonusService::class)->recordBasiqEvent($basiqChild);

        $this->artisan('mlm:calculate-weekly-bonuses')->assertSuccessful();
        $countAfterFirst = MlmBonusPayout::count();

        $this->artisan('mlm:calculate-weekly-bonuses')->assertSuccessful();
        $countAfterSecond = MlmBonusPayout::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }
}
