<?php

namespace Tests\Feature;

use App\Models\MlmAgentClosure;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre l'albero MLM (closure table): attachAgent, resolveAgentForNewClient,
 * orderedUpline/directDownline/branchSummaries/subtree, currentSponsor,
 * rootAgents, moveAgent (incluse le guardie no-self/no-ciclo).
 *
 * Vedi app/Services/MlmTreeService.php e MLM_PROPOSAL.md §3/§8.
 */
class MlmTreeServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
    }

    private function makeAgent(string $rank = 'start', array $overrides = []): User
    {
        return User::create(array_merge([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => $rank,
            'mlm_activated_at'     => now(),
        ], $overrides));
    }

    private function makeClient(?User $agent = null): User
    {
        return User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent?->id,
        ]);
    }

    public function test_attach_agent_without_sponsor_creates_only_the_self_row(): void
    {
        $root = $this->makeAgent();

        $this->tree->attachAgent($root, null);

        $rows = MlmAgentClosure::all();
        $this->assertCount(1, $rows);
        $this->assertSame($root->id, $rows[0]->ancestor_id);
        $this->assertSame($root->id, $rows[0]->descendant_id);
        $this->assertSame(0, $rows[0]->depth);
        $this->assertNull($rows[0]->branch_root_id);
    }

    public function test_attach_agent_links_the_full_upline_with_correct_branch_root(): void
    {
        $root = $this->makeAgent();
        $child = $this->makeAgent();
        $grandchild = $this->makeAgent();

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);
        $this->tree->attachAgent($grandchild, $child);

        // L'antenato "root" del nipote e' a depth 2, e la sua colonna (branch_root)
        // e' "child" (il figlio diretto di root attraverso cui passa il ramo).
        $rootToGrandchild = MlmAgentClosure::where('ancestor_id', $root->id)
            ->where('descendant_id', $grandchild->id)->first();
        $this->assertSame(2, $rootToGrandchild->depth);
        $this->assertSame($child->id, $rootToGrandchild->branch_root_id);

        // L'antenato diretto "child" del nipote e' a depth 1 con branch_root = se stesso (nipote).
        $childToGrandchild = MlmAgentClosure::where('ancestor_id', $child->id)
            ->where('descendant_id', $grandchild->id)->first();
        $this->assertSame(1, $childToGrandchild->depth);
        $this->assertSame($grandchild->id, $childToGrandchild->branch_root_id);
    }

    public function test_resolve_agent_for_new_client_returns_null_without_referrer(): void
    {
        $this->assertNull($this->tree->resolveAgentForNewClient(null));
    }

    public function test_resolve_agent_for_new_client_returns_the_referrer_when_agent(): void
    {
        $agent = $this->makeAgent();

        $resolved = $this->tree->resolveAgentForNewClient($agent);

        $this->assertSame($agent->id, $resolved->id);
    }

    public function test_resolve_agent_for_new_client_climbs_to_the_client_agent_when_referrer_is_a_client(): void
    {
        $agent = $this->makeAgent();
        $referrerClient = $this->makeClient($agent);

        $resolved = $this->tree->resolveAgentForNewClient($referrerClient);

        $this->assertSame($agent->id, $resolved->id);
    }

    public function test_resolve_agent_for_new_client_returns_null_for_an_orphan_client_referrer(): void
    {
        $orphanClient = $this->makeClient(null);

        $this->assertNull($this->tree->resolveAgentForNewClient($orphanClient));
    }

    public function test_ordered_upline_goes_from_nearest_to_farthest(): void
    {
        $root = $this->makeAgent();
        $child = $this->makeAgent();
        $grandchild = $this->makeAgent();

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);
        $this->tree->attachAgent($grandchild, $child);

        $upline = $this->tree->orderedUpline($grandchild);

        $this->assertSame([$child->id, $root->id], $upline->pluck('id')->all());
    }

    public function test_branch_summaries_group_descendants_by_first_level_column(): void
    {
        $root = $this->makeAgent();
        $branchA = $this->makeAgent('basic');
        $branchB = $this->makeAgent('key');
        $grandchildOfA = $this->makeAgent('start');

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($branchA, $root);
        $this->tree->attachAgent($branchB, $root);
        $this->tree->attachAgent($grandchildOfA, $branchA);

        $branches = $this->tree->branchSummaries($root);

        $this->assertCount(2, $branches);

        $summaryA = $branches->firstWhere('branch_root.id', $branchA->id);
        $this->assertSame(2, $summaryA['agent_count']); // branchA + il suo discendente

        $summaryB = $branches->firstWhere('branch_root.id', $branchB->id);
        $this->assertSame(1, $summaryB['agent_count']);
    }

    public function test_current_sponsor_reflects_live_position(): void
    {
        $root = $this->makeAgent();
        $child = $this->makeAgent();

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);

        $this->assertSame($root->id, $this->tree->currentSponsor($child)->id);
        $this->assertNull($this->tree->currentSponsor($root));
    }

    public function test_root_agents_excludes_agents_with_a_sponsor(): void
    {
        $root = $this->makeAgent();
        $child = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);

        $roots = $this->tree->rootAgents();

        $this->assertTrue($roots->contains('id', $root->id));
        $this->assertFalse($roots->contains('id', $child->id));
    }

    public function test_move_agent_reattaches_the_whole_subtree_to_the_new_sponsor(): void
    {
        $root1 = $this->makeAgent();
        $root2 = $this->makeAgent();
        $agentA = $this->makeAgent();
        $childB = $this->makeAgent();

        $this->tree->attachAgent($root1, null);
        $this->tree->attachAgent($root2, null);
        $this->tree->attachAgent($agentA, $root1);
        $this->tree->attachAgent($childB, $agentA);

        $this->tree->moveAgent($agentA, $root2);

        // Vecchio sponsor non piu' collegato ne' ad agentA ne' al suo sottoalbero.
        $this->assertFalse(MlmAgentClosure::where('ancestor_id', $root1->id)->where('descendant_id', $agentA->id)->exists());
        $this->assertFalse(MlmAgentClosure::where('ancestor_id', $root1->id)->where('descendant_id', $childB->id)->exists());

        // Nuovo sponsor collegato ad agentA e al suo intero sottoalbero, con depth corretta.
        $rootToAgentA = MlmAgentClosure::where('ancestor_id', $root2->id)->where('descendant_id', $agentA->id)->first();
        $this->assertSame(1, $rootToAgentA->depth);
        $rootToChildB = MlmAgentClosure::where('ancestor_id', $root2->id)->where('descendant_id', $childB->id)->first();
        $this->assertSame(2, $rootToChildB->depth);

        // Il legame interno del sottoalbero (agentA -> childB) resta intatto.
        $this->assertTrue(MlmAgentClosure::where('ancestor_id', $agentA->id)->where('descendant_id', $childB->id)->where('depth', 1)->exists());

        $this->assertSame($root2->id, $this->tree->currentSponsor($agentA)->id);
    }

    public function test_move_agent_to_root_removes_sponsor_without_adding_new_rows(): void
    {
        $root = $this->makeAgent();
        $agentA = $this->makeAgent();

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($agentA, $root);

        $this->tree->moveAgent($agentA, null);

        $this->assertNull($this->tree->currentSponsor($agentA));
        $this->assertFalse(MlmAgentClosure::where('ancestor_id', $root->id)->where('descendant_id', $agentA->id)->exists());
    }

    public function test_move_agent_rejects_self_sponsor(): void
    {
        $agentA = $this->makeAgent();
        $this->tree->attachAgent($agentA, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->tree->moveAgent($agentA, $agentA);
    }

    public function test_move_agent_rejects_moving_under_own_descendant(): void
    {
        $root = $this->makeAgent();
        $child = $this->makeAgent();
        $grandchild = $this->makeAgent();

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);
        $this->tree->attachAgent($grandchild, $child);

        $this->expectException(\InvalidArgumentException::class);
        $this->tree->moveAgent($root, $grandchild);
    }

    public function test_move_agent_rejects_a_non_agent(): void
    {
        $client = $this->makeClient();
        $sponsor = $this->makeAgent();
        $this->tree->attachAgent($sponsor, null);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->tree->moveAgent($client, $sponsor);
    }

    public function test_subtree_exposes_the_basiq_flag_per_node(): void
    {
        $sponsor = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic', ['mlm_basiq_at' => now()]);
        $notBasiq = $this->makeAgent('basic');

        $this->tree->attachAgent($sponsor, null);
        $this->tree->attachAgent($basiq, $sponsor);
        $this->tree->attachAgent($notBasiq, $sponsor);

        $tree = $this->tree->subtree($sponsor);

        $this->assertFalse($tree['basiq'], 'Lo sponsor senza mlm_basiq_at non deve risultare BasiQ.');

        $children = collect($tree['children'])->keyBy('id');
        $this->assertTrue($children[$basiq->id]['basiq']);
        $this->assertFalse($children[$notBasiq->id]['basiq']);
    }

    public function test_subtree_exposes_net_admin_granted_points_per_node(): void
    {
        $sponsor = $this->makeAgent('key');
        $gifted = $this->makeAgent('basic');

        $this->tree->attachAgent($sponsor, null);
        $this->tree->attachAgent($gifted, $sponsor);

        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $gifted->id, 'metric' => 'points',
            'amount' => 30, 'granted_by_admin_id' => $sponsor->id,
        ]);
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $gifted->id, 'metric' => 'points',
            'amount' => -5, 'granted_by_admin_id' => $sponsor->id,
        ]);
        // Revocato: non deve contare.
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $gifted->id, 'metric' => 'points',
            'amount' => 100, 'granted_by_admin_id' => $sponsor->id,
        ])->forceFill(['revoked_at' => now()])->save();
        // Metrica diversa: non deve contare nei punti.
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $gifted->id, 'metric' => 'level1_basic_count',
            'amount' => 2, 'granted_by_admin_id' => $sponsor->id,
        ]);

        $tree = $this->tree->subtree($sponsor);

        $this->assertSame(0, $tree['granted_points']);
        $this->assertSame(25, $tree['children'][0]['granted_points']);
    }

    public function test_subtree_exposes_cumulative_branch_points_per_node(): void
    {
        // root(10) ── A(100) ── A1(50)
        //        └─── B(nessun punto)
        // branch_points: A1=50, A=150, B=0, root=160. Un punto SCADUTO su A
        // non deve contare (stessa finestra di validita' di branchSummaries).
        $root = $this->makeAgent('key');
        $a = $this->makeAgent('basic');
        $a1 = $this->makeAgent('start');
        $b = $this->makeAgent('start');

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($a, $root);
        $this->tree->attachAgent($a1, $a);
        $this->tree->attachAgent($b, $root);

        $mkPoints = function (User $agent, float $points, bool $expired = false): void {
            \App\Models\MlmPointLedgerEntry::create([
                'agent_user_id' => $agent->id,
                'client_user_id' => $this->makeClient($agent)->id,
                'source_type'   => 'registration',
                'points'        => $points,
                'valid_from'    => now()->subMonths(2)->toDateString(),
                'valid_until'   => $expired ? now()->subDay()->toDateString() : now()->addMonth()->toDateString(),
            ]);
        };

        $mkPoints($root, 10);
        $mkPoints($a, 100);
        $mkPoints($a, 999, expired: true); // fuori finestra: ignorato
        $mkPoints($a1, 50);

        $tree = $this->tree->subtree($root);

        $this->assertSame(160.0, (float) $tree['branch_points']);
        $this->assertSame(10.0, (float) $tree['points'], 'points resta il valore del solo nodo');

        $children = collect($tree['children'])->keyBy('id');
        $this->assertSame(150.0, (float) $children[$a->id]['branch_points']);
        $this->assertSame(0.0, (float) $children[$b->id]['branch_points']);
        $this->assertSame(50.0, (float) $children[$a->id]['children'][0]['branch_points']);
    }

    public function test_subtree_branch_points_include_granted_points_from_downline_members(): void
    {
        // root(10,0) ── A(100,+50) ── A1(50,0)
        //          └─── B(0,+20)
        // 2026-07-22 pomeriggio bis (richiesta di Laura): branch_points deve
        // includere l'omaggio, perche' conta per la soglia dei 300 punti
        // (MlmRankEngine::evaluate, branches_300pt). branch_points_real e
        // branch_granted_points espongono la scomposizione per le viste.
        $root = $this->makeAgent('key');
        $a = $this->makeAgent('basic');
        $a1 = $this->makeAgent('start');
        $b = $this->makeAgent('start');

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($a, $root);
        $this->tree->attachAgent($a1, $a);
        $this->tree->attachAgent($b, $root);

        $mkPoints = function (User $agent, float $points): void {
            \App\Models\MlmPointLedgerEntry::create([
                'agent_user_id' => $agent->id,
                'client_user_id' => $this->makeClient($agent)->id,
                'source_type'   => 'registration',
                'points'        => $points,
                'valid_from'    => now()->subMonth()->toDateString(),
                'valid_until'   => now()->addMonth()->toDateString(),
            ]);
        };

        $mkPoints($root, 10);
        $mkPoints($a, 100);
        $mkPoints($a1, 50);

        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $a->id, 'metric' => 'points',
            'amount' => 50, 'granted_by_admin_id' => $root->id,
        ]);
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $b->id, 'metric' => 'points',
            'amount' => 20, 'granted_by_admin_id' => $root->id,
        ]);

        $tree = $this->tree->subtree($root);
        $children = collect($tree['children'])->keyBy('id');

        // A: 100 reali (propri) + 50 reali di A1 = 150 reali; +50 omaggio (propri).
        $this->assertSame(150.0, (float) $children[$a->id]['branch_points_real']);
        $this->assertSame(50, $children[$a->id]['branch_granted_points']);
        $this->assertSame(200.0, (float) $children[$a->id]['branch_points']);

        // B: 0 reali, +20 omaggio.
        $this->assertSame(0.0, (float) $children[$b->id]['branch_points_real']);
        $this->assertSame(20, $children[$b->id]['branch_granted_points']);
        $this->assertSame(20.0, (float) $children[$b->id]['branch_points']);

        // root: 10 (propri, reali) + 150 (A reali) + 0 (B reali) = 160 reali;
        // 0 + 50 (A) + 20 (B) = 70 omaggio cumulati; totale 230.
        $this->assertSame(160.0, (float) $tree['branch_points_real']);
        $this->assertSame(70, $tree['branch_granted_points']);
        $this->assertSame(230.0, (float) $tree['branch_points']);
    }

    public function test_subtree_branch_points_never_go_below_zero_when_a_correction_outweighs_real_points(): void
    {
        // Un ramo con pochi punti reali e una correzione omaggio negativa
        // superiore ad essi non deve mai mostrare un totale negativo (stessa
        // convenzione di branchSummaries()/User::mlmActivePoints()).
        $root = $this->makeAgent('key');
        $child = $this->makeAgent('start');
        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($child, $root);

        \App\Models\MlmPointLedgerEntry::create([
            'agent_user_id' => $child->id,
            'client_user_id' => $this->makeClient($child)->id,
            'source_type'   => 'registration',
            'points'        => 5,
            'valid_from'    => now()->subMonth()->toDateString(),
            'valid_until'   => now()->addMonth()->toDateString(),
        ]);
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $child->id, 'metric' => 'points',
            'amount' => -20, 'granted_by_admin_id' => $root->id,
        ]);

        $tree = $this->tree->subtree($root);
        $childNode = collect($tree['children'])->keyBy('id')[$child->id];

        $this->assertSame(5.0, (float) $childNode['branch_points_real']);
        $this->assertSame(-20, $childNode['branch_granted_points']);
        $this->assertSame(0.0, (float) $childNode['branch_points'], 'Il totale clampato non scende mai sotto zero.');
    }
}
