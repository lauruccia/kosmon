<?php

namespace Tests\Feature;

use App\Models\MlmCommission;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\User;
use App\Services\MlmCommissionEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il motore commissioni mensili (dirette + indirette), incluso il
 * caso confermato da Laura il 2026-07-03: livello 5 = 8% uniforme per
 * qualsiasi agente, 0,5% dal livello 6 in poi SOLO per Top/SuperVisor/Manager,
 * con breakaway sul primo nodo Top+ incontrato in ciascun ramo.
 *
 * Dal 2026-07-13 (conferma di Laura): GATING dei livelli indiretti 1-5 in
 * base ai requisiti personali del beneficiario (tabella "Criteri per i
 * Compensi Indiretti", 2°ParteKnm slide 7): I=12pt/0 Basic, II=12pt/2 Basic,
 * III=24pt/2 Basic, IV=24pt/2 Basic, V=48pt/3 Basic.
 *
 * Vedi app/Services/MlmCommissionEngine.php e
 * [[mlm_livello5_8percento_da_confermare]] in memoria di progetto.
 */
class MlmCommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    private MlmCommissionEngine $engine;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->engine = new MlmCommissionEngine($this->tree);
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

    private function givePoints(User $agent, int $points): void
    {
        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $this->makeClient($agent)->id,
            'source_type'    => 'registration',
            'points'         => $points,
            // runForMonth() valuta i punti attivi COME ERANO all'inizio del mese
            // (mlmActivePoints($periodMonth)), non ad oggi: valid_from deve quindi
            // coprire l'inizio del mese corrente, non solo "ieri".
            'valid_from'     => now()->startOfMonth()->subDay()->toDateString(),
            'valid_until'    => now()->addMonth()->toDateString(),
        ]);
    }

    /** Attacca N figli diretti di rank basic (per i requisiti di gating). */
    private function attachBasicChildren(User $agent, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $child = $this->makeAgent('basic');
            $this->tree->attachAgent($child, $agent);
        }
    }

    /** Cliente diretto dell'agente con una base commissionabile mensile attiva. */
    private function giveMonthlyBase(User $agent, int $monthlyAmountEurCents): User
    {
        $client = $this->makeClient($agent);

        MlmCommissionBaseLedgerEntry::create([
            'client_user_id'           => $client->id,
            'direct_agent_id'          => $agent->id,
            'monthly_amount_eur_cents' => $monthlyAmountEurCents,
            'valid_from'               => now()->startOfMonth()->toDateString(),
            'valid_until'              => now()->addMonths(11)->toDateString(),
        ]);

        return $client;
    }

    public function test_direct_percentage_table_thresholds(): void
    {
        $this->assertSame(0.0, $this->engine->directPercentage(0));
        $this->assertSame(0.0, $this->engine->directPercentage(5));
        $this->assertSame(0.05, $this->engine->directPercentage(6));
        $this->assertSame(0.05, $this->engine->directPercentage(11));
        $this->assertSame(0.10, $this->engine->directPercentage(12));
        $this->assertSame(0.15, $this->engine->directPercentage(24));
        $this->assertSame(0.20, $this->engine->directPercentage(48));
        $this->assertSame(0.25, $this->engine->directPercentage(96));
        $this->assertSame(0.30, $this->engine->directPercentage(150));
        $this->assertSame(0.40, $this->engine->directPercentage(200));
        $this->assertSame(0.40, $this->engine->directPercentage(1000));
    }

    public function test_run_for_month_creates_a_direct_commission_based_on_agent_points(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 50); // >= 48 -> 20%
        $this->giveMonthlyBase($agent, 10_000);

        $this->engine->runForMonth(now());

        $commission = MlmCommission::where('agent_user_id', $agent->id)->where('type', 'diretta')->first();
        $this->assertNotNull($commission);
        $this->assertSame(10_000, $commission->base_amount_eur_cents);
        $this->assertEqualsWithDelta(20.0, (float) $commission->percentage, 0.01);
        $this->assertSame(2_000, $commission->amount_eur_cents);
    }

    public function test_run_for_month_is_idempotent(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 50);
        $this->giveMonthlyBase($agent, 10_000);

        $this->engine->runForMonth(now());
        $countAfterFirstRun = MlmCommission::count();

        $this->engine->runForMonth(now());
        $countAfterSecondRun = MlmCommission::count();

        $this->assertSame($countAfterFirstRun, $countAfterSecondRun);
        $this->assertGreaterThan(0, $countAfterFirstRun);
    }

    public function test_no_direct_commission_below_the_minimum_points_threshold(): void
    {
        $agent = $this->makeAgent();
        $this->givePoints($agent, 3); // sotto la soglia minima (6)
        $this->giveMonthlyBase($agent, 10_000);

        $this->engine->runForMonth(now());

        $this->assertSame(0, MlmCommission::where('agent_user_id', $agent->id)->count());
    }

    public function test_indirect_commissions_levels_1_to_5_use_the_uniform_percentage_table(): void
    {
        // root -> l1 -> l2 -> l3 -> l4 -> l5, ciascuno con un cliente diretto da 10.000 EUR/mese.
        // Il root soddisfa i requisiti di gating fino al V livello (48 punti
        // personali + 3 Basic al 1° livello, tabella slide 7).
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->givePoints($root, 48);
        $this->attachBasicChildren($root, 3);

        $levels = [];
        $sponsor = $root;
        for ($i = 1; $i <= 5; $i++) {
            $agent = $this->makeAgent();
            $this->tree->attachAgent($agent, $sponsor);
            $this->giveMonthlyBase($agent, 10_000);
            $levels[$i] = $agent;
            $sponsor = $agent;
        }

        $this->engine->runForMonth(now());

        $expectedPercentages = [1 => 4.0, 2 => 2.0, 3 => 1.0, 4 => 0.5, 5 => 8.0];
        $expectedAmounts = [1 => 400, 2 => 200, 3 => 100, 4 => 50, 5 => 800];

        foreach ($levels as $depth => $agent) {
            $commission = MlmCommission::where('agent_user_id', $root->id)
                ->where('type', 'indiretta')
                ->where('source_agent_id', $agent->id)
                ->first();

            $this->assertNotNull($commission, "Manca la commissione indiretta per il livello {$depth}");
            $this->assertEqualsWithDelta($expectedPercentages[$depth], (float) $commission->percentage, 0.01, "Percentuale errata al livello {$depth}");
            $this->assertSame($expectedAmounts[$depth], $commission->amount_eur_cents, "Importo errato al livello {$depth}");
        }
    }

    public function test_indirect_commission_requires_12_personal_points_for_level_1(): void
    {
        // Gating slide 7: senza 12 punti personali attivi il livello I non paga.
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);

        $l1 = $this->makeAgent();
        $this->tree->attachAgent($l1, $root);
        $this->giveMonthlyBase($l1, 10_000);

        $this->engine->runForMonth(now());

        $this->assertSame(
            0,
            MlmCommission::where('agent_user_id', $root->id)->where('type', 'indiretta')->count(),
            'Senza 12 punti personali il root non deve incassare indirette.'
        );
    }

    public function test_indirect_level_2_requires_2_basic_children(): void
    {
        // Gating slide 7: 12 punti bastano per il livello I (4%), ma il
        // livello II (2%) richiede anche 2 Basic al 1° livello.
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->givePoints($root, 12);

        $l1 = $this->makeAgent();
        $this->tree->attachAgent($l1, $root);
        $this->giveMonthlyBase($l1, 10_000);

        $l2 = $this->makeAgent();
        $this->tree->attachAgent($l2, $l1);
        $this->giveMonthlyBase($l2, 10_000);

        $this->engine->runForMonth(now());

        $level1 = MlmCommission::where('agent_user_id', $root->id)
            ->where('source_agent_id', $l1->id)->first();
        $this->assertNotNull($level1);
        $this->assertEqualsWithDelta(4.0, (float) $level1->percentage, 0.01);

        $this->assertSame(
            0,
            MlmCommission::where('agent_user_id', $root->id)->where('source_agent_id', $l2->id)->count(),
            'Senza 2 Basic al 1° livello il II livello non deve pagare.'
        );
    }

    public function test_indirect_level_5_requires_48_points_and_3_basic_children(): void
    {
        // Gating slide 7: con 24 punti e 2 Basic si arriva al IV livello,
        // ma il V (8%) richiede 48 punti e 3 Basic.
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->givePoints($root, 24);
        $this->attachBasicChildren($root, 2);

        $sponsor = $root;
        $agents = [];
        for ($i = 1; $i <= 5; $i++) {
            $agent = $this->makeAgent();
            $this->tree->attachAgent($agent, $sponsor);
            $this->giveMonthlyBase($agent, 10_000);
            $agents[$i] = $agent;
            $sponsor = $agent;
        }

        $this->engine->runForMonth(now());

        $level4 = MlmCommission::where('agent_user_id', $root->id)
            ->where('source_agent_id', $agents[4]->id)->first();
        $this->assertNotNull($level4);
        $this->assertEqualsWithDelta(0.5, (float) $level4->percentage, 0.01);

        $this->assertSame(
            0,
            MlmCommission::where('agent_user_id', $root->id)->where('source_agent_id', $agents[5]->id)->count(),
            'Senza 48 punti + 3 Basic il V livello (8%) non deve pagare.'
        );
    }

    public function test_indirect_commission_beyond_level_5_is_zero_without_breakaway_rank(): void
    {
        $root = $this->makeAgent('basic'); // NON top/supervisor/manager
        $this->tree->attachAgent($root, null);

        $sponsor = $root;
        for ($i = 1; $i <= 6; $i++) {
            $agent = $this->makeAgent();
            $this->tree->attachAgent($agent, $sponsor);
            $this->giveMonthlyBase($agent, 10_000);
            $sponsor = $agent;
            if ($i === 6) {
                $levelSixAgent = $agent;
            }
        }

        $this->engine->runForMonth(now());

        $this->assertSame(
            0,
            MlmCommission::where('agent_user_id', $root->id)->where('source_agent_id', $levelSixAgent->id)->count()
        );
    }

    public function test_indirect_commission_beyond_level_5_applies_for_breakaway_rank_and_stops_descent(): void
    {
        $root = $this->makeAgent('top'); // qualifica per l'estensione oltre il 5° livello

        $this->tree->attachAgent($root, null);
        $this->givePoints($root, 48);
        $this->attachBasicChildren($root, 4);

        $sponsor = $root;
        $agents = [];
        for ($i = 1; $i <= 6; $i++) {
            // Il nodo di livello 6 e' a sua volta un rank "breakaway" (supervisor):
            // deve ricevere la commissione ma bloccare la discesa oltre di lui.
            $rank = $i === 6 ? 'supervisor' : 'start';
            $agent = $this->makeAgent($rank);
            $this->tree->attachAgent($agent, $sponsor);
            $this->giveMonthlyBase($agent, 10_000);
            $agents[$i] = $agent;
            $sponsor = $agent;
        }

        // Livello 7, oltre il breakaway: non deve mai essere raggiunto.
        $levelSeven = $this->makeAgent();
        $this->tree->attachAgent($levelSeven, $sponsor);
        $this->giveMonthlyBase($levelSeven, 10_000);

        $this->engine->runForMonth(now());

        $levelSixCommission = MlmCommission::where('agent_user_id', $root->id)
            ->where('source_agent_id', $agents[6]->id)->first();
        $this->assertNotNull($levelSixCommission);
        $this->assertEqualsWithDelta(0.5, (float) $levelSixCommission->percentage, 0.01);
        $this->assertSame(50, $levelSixCommission->amount_eur_cents);

        $this->assertSame(
            0,
            MlmCommission::where('agent_user_id', $root->id)->where('source_agent_id', $levelSeven->id)->count(),
            'Il breakaway al livello 6 (rank supervisor) deve bloccare la discesa al livello 7.'
        );
    }
}
