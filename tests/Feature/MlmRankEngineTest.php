<?php

namespace Tests\Feature;

use App\Models\MlmPointLedgerEntry;
use App\Models\MlmRankHistory;
use App\Models\User;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il motore qualifiche: requisiti indipendenti per grado (non
 * progressione stretta), promozione automatica, e la regola "mai
 * retrocessione automatica" anche se i punti scendono sotto soglia.
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
        $this->engine = new MlmRankEngine($this->tree);
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

    public function test_promote_if_eligible_creates_rank_history_and_updates_agent(): void
    {
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 12);

        $promoted = $this->engine->promoteIfEligible($agent);

        $this->assertTrue($promoted);
        $this->assertSame('basic', $agent->fresh()->mlm_rank);
        $this->assertSame(1, MlmRankHistory::where('agent_user_id', $agent->id)->count());
    }

    public function test_promote_if_eligible_never_demotes(): void
    {
        $agent = $this->makeAgent('top'); // rank gia' alto, senza i requisiti strutturali

        $promoted = $this->engine->promoteIfEligible($agent);

        $this->assertFalse($promoted);
        $this->assertSame('top', $agent->fresh()->mlm_rank);
        $this->assertSame(0, MlmRankHistory::where('agent_user_id', $agent->id)->count());
    }

    public function test_promote_if_eligible_is_idempotent_once_at_target_rank(): void
    {
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 12);

        $this->assertTrue($this->engine->promoteIfEligible($agent));
        $this->assertFalse($this->engine->promoteIfEligible($agent->fresh()));
        $this->assertSame(1, MlmRankHistory::where('agent_user_id', $agent->id)->count());
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
