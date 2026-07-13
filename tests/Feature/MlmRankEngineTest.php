<?php

namespace Tests\Feature;

use App\Models\MlmPointLedgerEntry;
use App\Models\MlmRankHistory;
use App\Models\User;
use App\Services\MlmAwardService;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il motore qualifiche: requisiti indipendenti per grado (non
 * progressione stretta), promozione automatica e RETROCESSIONE automatica
 * quando i requisiti vengono meno (es. punti scaduti nel ledger —
 * confermata da Laura il 2026-07-13), inclusa la cascata bottom-up del
 * job notturno mlm:recalculate-points.
 *
 * Requisiti Senior/Top allineati al testo letterale delle slide
 * (2026-07-13): Senior = 48pt + 3 Basic + 2 Key su 2 colonne;
 * Top = 48pt + 4 Basic + 3 colonne da 300 punti.
 *
 * Vedi app/Services/MlmRankEngine.php e [[project_mlm_knm]] in memoria.
 */
class MlmRankEngineTest extends TestCase
{
    use RefreshDatabase;

    private MlmRankEngine $engine;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->engine = new MlmRankEngine($this->tree, new MlmAwardService());
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

    private function giveExpiredPoints(User $agent, int $points): void
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
            'valid_until'    => now()->subDay()->toDateString(), // gia' scaduti
        ]);
    }

    private function giveActivePoints(User $agent, int $points): void
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
            'valid_from'     => now()->subDay()->toDateString(),
            'valid_until'    => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_evaluate_promotes_to_basic_with_12_active_points(): void
    {
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 12);

        $evaluation = $this->engine->evaluate($agent);

        $this->assertSame('basic', $evaluation['eligible_rank']);
        $this->assertTrue($evaluation['satisfied']['basic']);
        $this->assertFalse($evaluation['satisfied']['key']);
    }

    public function test_evaluate_requires_two_basic_children_for_key(): void
    {
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 24);

        $child1 = $this->makeAgent('basic');
        $child2 = $this->makeAgent('basic');
        $this->tree->attachAgent($child1, $agent);
        $this->tree->attachAgent($child2, $agent);

        $evaluation = $this->engine->evaluate($agent);

        $this->assertSame('key', $evaluation['eligible_rank']);
        $this->assertSame(2, $evaluation['level1_basic_count']);
    }

    public function test_evaluate_does_not_grant_key_with_only_one_basic_child(): void
    {
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 24);

        $child1 = $this->makeAgent('basic');
        $this->tree->attachAgent($child1, $agent);

        $evaluation = $this->engine->evaluate($agent);

        $this->assertFalse($evaluation['satisfied']['key']);
        $this->assertSame('basic', $evaluation['eligible_rank']);
    }

    public function test_sync_rank_promotes_and_creates_rank_history(): void
    {
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 12);

        $result = $this->engine->syncRank($agent);

        $this->assertSame('promoted', $result);
        $this->assertSame('basic', $agent->fresh()->mlm_rank);
        $this->assertSame(1, MlmRankHistory::where('agent_user_id', $agent->id)->count());
    }

    public function test_sync_rank_demotes_when_requirements_are_no_longer_met(): void
    {
        // Rank alto ma punti ormai SCADUTI e nessuna struttura: retrocede
        // direttamente al grado piu' alto ancora soddisfatto (start).
        $agent = $this->makeAgent('top');
        $this->giveExpiredPoints($agent, 48);

        $result = $this->engine->syncRank($agent);

        $this->assertSame('demoted', $result);
        $this->assertSame('start', $agent->fresh()->mlm_rank);
        $this->assertSame(1, MlmRankHistory::where('agent_user_id', $agent->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event'        => 'mlm.rank_demoted',
            'auditable_id' => $agent->id,
        ]);
    }

    public function test_sync_rank_demotes_to_the_highest_rank_still_satisfied(): void
    {
        // Key con 24 punti ancora attivi ma senza piu' i 2 Basic al 1°
        // livello: scende a basic (12+ punti), non a start.
        $agent = $this->makeAgent('key');
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 24);

        $result = $this->engine->syncRank($agent);

        $this->assertSame('demoted', $result);
        $this->assertSame('basic', $agent->fresh()->mlm_rank);
    }

    public function test_sync_rank_is_idempotent_once_at_target_rank(): void
    {
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 12);

        $this->assertSame('promoted', $this->engine->syncRank($agent));
        $this->assertNull($this->engine->syncRank($agent->fresh()));
        $this->assertSame(1, MlmRankHistory::where('agent_user_id', $agent->id)->count());
    }

    public function test_sync_rank_does_not_touch_the_basiq_flag_on_demotion(): void
    {
        $agent = $this->makeAgent('basic');
        $agent->forceFill(['mlm_basiq_at' => now()->subMonth()])->save();
        $this->giveExpiredPoints($agent, 12);

        $this->assertSame('demoted', $this->engine->syncRank($agent));
        $this->assertSame('start', $agent->fresh()->mlm_rank);
        $this->assertNotNull($agent->fresh()->mlm_basiq_at, 'BasiQ e\' storico: la retrocessione non lo cancella.');
    }

    public function test_senior_requires_3_basic_and_2_key_on_2_different_columns(): void
    {
        // Requisito da slide: 48 pt + 3 Basic al 1° livello + 2 Key su 2
        // colonne diverse (i Key contano anche come Basic al 1° livello).
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 48);

        $key1 = $this->makeAgent('key');
        $key2 = $this->makeAgent('key');
        $basic = $this->makeAgent('basic');
        foreach ([$key1, $key2, $basic] as $child) {
            $this->tree->attachAgent($child, $agent);
        }

        $evaluation = $this->engine->evaluate($agent);

        $this->assertTrue($evaluation['satisfied']['senior']);
        $this->assertFalse($evaluation['satisfied']['top'], 'Senza 4 Basic e 3 colonne da 300 punti non e\' Top.');
        $this->assertSame('senior', $evaluation['eligible_rank']);
    }

    public function test_top_requires_4_basic_and_3_columns_with_300_points(): void
    {
        // Requisito da slide: 48 pt + 4 Basic al 1° livello + 3 colonne da
        // 300 punti attivi ciascuna.
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 48);

        $children = [];
        for ($i = 0; $i < 4; $i++) {
            $child = $this->makeAgent('basic');
            $this->tree->attachAgent($child, $agent);
            $children[] = $child;
        }

        // 3 colonne su 4 raggiungono i 300 punti attivi.
        foreach (array_slice($children, 0, 3) as $child) {
            $this->giveActivePoints($child, 300);
        }

        $evaluation = $this->engine->evaluate($agent);

        $this->assertTrue($evaluation['satisfied']['top']);
        $this->assertSame(3, $evaluation['branches_300pt']);
        $this->assertSame('top', $evaluation['eligible_rank']);
    }

    public function test_supervisor_requires_5_basic_and_2_senior_and_2_top_on_4_different_columns(): void
    {
        // Requisito da slide (confermata da Laura il 2026-07-13, "Qualifiche"
        // KNM): 48 pt + 5 Basic al 1° livello + 2 Senior e 2 Top su 4 colonne
        // diverse. I 2 Top contano anche come colonne "con almeno un Senior",
        // quindi bastano altre 2 colonne pure-Senior per arrivare a 4 totali.
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 48);

        $children = [
            $this->makeAgent('top'),
            $this->makeAgent('top'),
            $this->makeAgent('senior'),
            $this->makeAgent('senior'),
            $this->makeAgent('basic'),
        ];
        foreach ($children as $child) {
            $this->tree->attachAgent($child, $agent);
        }

        $evaluation = $this->engine->evaluate($agent);

        $this->assertTrue($evaluation['satisfied']['supervisor']);
        $this->assertFalse($evaluation['satisfied']['top'], 'Nessuna colonna ha 300 punti attivi: non e\' (anche) Top.');
        $this->assertSame(5, $evaluation['level1_basic_count']);
        $this->assertSame(2, $evaluation['branches_with_top']);
        $this->assertSame(4, $evaluation['branches_with_senior']);
        $this->assertSame('supervisor', $evaluation['eligible_rank']);
    }

    public function test_supervisor_is_not_satisfied_with_only_2_senior_columns_and_no_top(): void
    {
        // Controprova: 4 colonne Senior ma nessuna arrivata a Top non basta
        // ("2 Senior E 2 Top", non 4 Senior generici).
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 48);

        for ($i = 0; $i < 5; $i++) {
            $child = $this->makeAgent($i < 4 ? 'senior' : 'basic');
            $this->tree->attachAgent($child, $agent);
        }

        $evaluation = $this->engine->evaluate($agent);

        $this->assertFalse($evaluation['satisfied']['supervisor']);
        $this->assertSame(0, $evaluation['branches_with_top']);
    }

    public function test_manager_requires_6_basic_and_3_supervisor_on_3_different_columns(): void
    {
        // Requisito da slide: 48 pt + 6 Basic al 1° livello + 3 SuperVisor su
        // 3 colonne diverse. Ogni qualifica e' un requisito indipendente (non
        // una progressione stretta): qui "top" e "supervisor" NON sono
        // soddisfatte (mancano le 300 punti/colonna e la 4a colonna Senior),
        // eppure "manager" lo e'.
        $agent = $this->makeAgent();
        $this->tree->attachAgent($agent, null);
        $this->giveActivePoints($agent, 48);

        $children = [
            $this->makeAgent('supervisor'),
            $this->makeAgent('supervisor'),
            $this->makeAgent('supervisor'),
            $this->makeAgent('basic'),
            $this->makeAgent('basic'),
            $this->makeAgent('basic'),
        ];
        foreach ($children as $child) {
            $this->tree->attachAgent($child, $agent);
        }

        $evaluation = $this->engine->evaluate($agent);

        $this->assertTrue($evaluation['satisfied']['manager']);
        $this->assertSame(6, $evaluation['level1_basic_count']);
        $this->assertSame(3, $evaluation['branches_with_supervisor']);
        $this->assertSame('manager', $evaluation['eligible_rank']);
    }

    public function test_nightly_command_cascades_demotions_bottom_up_in_a_single_run(): void
    {
        // parent (key) con 2 figli basic: i punti dei FIGLI scadono ->
        // i figli retrocedono a start e il parent, valutato DOPO di loro
        // (ordine bottom-up), perde i "2 Basic al 1° livello" e scende a
        // basic nella STESSA esecuzione del comando.
        $parent = $this->makeAgent('key');
        $this->tree->attachAgent($parent, null);
        $this->giveActivePoints($parent, 24);

        $children = [];
        for ($i = 0; $i < 2; $i++) {
            $child = $this->makeAgent('basic');
            $this->tree->attachAgent($child, $parent);
            $this->giveExpiredPoints($child, 12);
            $children[] = $child;
        }

        $this->artisan('mlm:recalculate-points')->assertSuccessful();

        foreach ($children as $child) {
            $this->assertSame('start', $child->fresh()->mlm_rank);
        }
        $this->assertSame('basic', $parent->fresh()->mlm_rank, 'La retrocessione dei figli deve propagarsi al parent nello stesso run.');
    }

    public function test_next_rank_requirements_returns_checklist_for_the_immediate_next_rank(): void
    {
        $agent = $this->makeAgent('basic');
        $this->giveActivePoints($agent, 24);

        $next = $this->engine->nextRankRequirements($agent);

        $this->assertSame('key', $next['rank']);
        $pointsItem = collect($next['items'])->firstWhere('label', 'Punti attivi');
        $this->assertTrue($pointsItem['met']);
    }

    public function test_next_rank_requirements_is_null_at_max_rank(): void
    {
        $agent = $this->makeAgent('manager');

        $this->assertNull($this->engine->nextRankRequirements($agent));
    }
}
