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
}
