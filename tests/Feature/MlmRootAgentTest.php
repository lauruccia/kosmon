<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre la regola "radice unica" del sistema MLM (2026-07-15): un solo
 * agente radice per tutto l'albero, scelto dall'admin, invece di una
 * foresta di alberi indipendenti (com'era prima). Vedi
 * app/Services/MlmTreeService.php (systemRootAgent, setSystemRootAgent,
 * guardia in moveAgent, fallback in attachAgent) e mlm_agente_radice_unico
 * nella memoria di progetto.
 *
 * Nota: senza radice designata (mlm_root_agent_id = null, il default) tutto
 * il comportamento resta identico a prima — vedi MlmTreeServiceTest per la
 * copertura del comportamento "bootstrap". Qui copriamo solo cosa cambia
 * QUANDO una radice e' stata designata.
 */
class MlmRootAgentTest extends TestCase
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

    private function makeAdmin(): User
    {
        $user = User::create([
            'name'                => 'Admin',
            'email'                => 'admin-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_without_a_configured_root_a_new_agent_without_sponsor_becomes_an_independent_root(): void
    {
        $agent = $this->makeAgent();

        $this->tree->attachAgent($agent, null);

        $this->assertNull($this->tree->currentSponsor($agent));
        $this->assertTrue($this->tree->rootAgents()->contains('id', $agent->id));
    }

    public function test_once_a_root_is_configured_a_new_agent_without_sponsor_attaches_under_it(): void
    {
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->tree->setSystemRootAgent($root);

        $newAgent = $this->makeAgent();
        $this->tree->attachAgent($newAgent, null);

        $this->assertSame($root->id, $this->tree->currentSponsor($newAgent)->id);
        $this->assertFalse($this->tree->rootAgents()->contains('id', $newAgent->id));
    }

    public function test_the_designated_root_itself_can_still_attach_without_a_sponsor(): void
    {
        $root = $this->makeAgent();

        // Il root e' gia' "agente" ma non ancora nella closure table: simula
        // l'ordine reale (agente creato, poi designato radice, poi attaccato).
        SystemSetting::mlmSettings()->forceFill(['mlm_root_agent_id' => $root->id])->save();

        $this->tree->attachAgent($root, null);

        $this->assertNull($this->tree->currentSponsor($root));
    }

    public function test_move_agent_to_no_sponsor_is_blocked_for_a_non_root_agent_once_a_root_is_configured(): void
    {
        $root = $this->makeAgent();
        $other = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($other, $root);
        $this->tree->setSystemRootAgent($root);

        $this->expectException(\InvalidArgumentException::class);
        $this->tree->moveAgent($other, null);
    }

    public function test_move_agent_to_no_sponsor_still_works_for_the_root_itself(): void
    {
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->tree->setSystemRootAgent($root);

        // No-op strutturale (era gia' senza sponsor), ma non deve lanciare.
        $this->tree->moveAgent($root, null);

        $this->assertNull($this->tree->currentSponsor($root));
    }

    public function test_set_system_root_agent_consolidates_pre_existing_independent_trees(): void
    {
        $futureRoot = $this->makeAgent();
        $orphanA = $this->makeAgent();
        $orphanAChild = $this->makeAgent();
        $orphanB = $this->makeAgent();

        $this->tree->attachAgent($futureRoot, null);
        $this->tree->attachAgent($orphanA, null);
        $this->tree->attachAgent($orphanAChild, $orphanA);
        $this->tree->attachAgent($orphanB, null);

        $this->assertCount(3, $this->tree->rootAgents()); // futureRoot, orphanA, orphanB

        $consolidated = $this->tree->setSystemRootAgent($futureRoot);

        $this->assertSame(2, $consolidated); // orphanA (col suo sottoalbero) + orphanB

        $roots = $this->tree->rootAgents();
        $this->assertCount(1, $roots);
        $this->assertSame($futureRoot->id, $roots->first()->id);

        $this->assertSame($futureRoot->id, $this->tree->currentSponsor($orphanA)->id);
        $this->assertSame($futureRoot->id, $this->tree->currentSponsor($orphanB)->id);
        // Il sottoalbero di orphanA resta intatto sotto di lui, solo ricollegato.
        $this->assertSame($orphanA->id, $this->tree->currentSponsor($orphanAChild)->id);

        $this->assertSame($futureRoot->id, $this->tree->systemRootAgent()->id);

        $this->assertTrue(AuditLog::where('event', 'mlm.system_root_agent_set')
            ->where('auditable_id', $futureRoot->id)
            ->exists());
    }

    public function test_set_system_root_agent_detaches_the_new_root_before_reattaching_its_old_tree_without_a_cycle(): void
    {
        // Scenario "chicken-and-egg": oldRoot e' la radice attuale, e newRoot
        // e' oggi un discendente di oldRoot. Cambiare radice a newRoot non
        // deve creare un ciclo (oldRoot finirebbe sotto un proprio discendente).
        $oldRoot = $this->makeAgent();
        $newRoot = $this->makeAgent();
        $this->tree->attachAgent($oldRoot, null);
        $this->tree->attachAgent($newRoot, $oldRoot);
        $this->tree->setSystemRootAgent($oldRoot);

        $this->tree->setSystemRootAgent($newRoot);

        $this->assertNull($this->tree->currentSponsor($newRoot));
        $this->assertSame($newRoot->id, $this->tree->currentSponsor($oldRoot)->id);
        $this->assertSame($newRoot->id, $this->tree->systemRootAgent()->id);
    }

    public function test_set_system_root_agent_rejects_a_non_agent(): void
    {
        $notAnAgent = User::create([
            'name'               => 'Cliente ' . Str::random(6),
            'email'               => 'cliente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'cliente',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->tree->setSystemRootAgent($notAnAgent);
    }

    public function test_admin_root_agent_page_shows_orphan_count_and_updating_it_consolidates(): void
    {
        $admin = $this->makeAdmin();

        $futureRoot = $this->makeAgent();
        $orphan = $this->makeAgent();
        $this->tree->attachAgent($futureRoot, null);
        $this->tree->attachAgent($orphan, null);

        $this->actingAs($admin)
            ->get(route('admin.mlm.settings.root-agent'))
            ->assertOk()
            ->assertViewHas('orphanCount', 2)
            ->assertViewHas('currentRoot', null);

        $this->actingAs($admin)
            ->post(route('admin.mlm.settings.root-agent.update'), ['root_agent_id' => $futureRoot->id])
            ->assertRedirect(route('admin.mlm.settings.root-agent'));

        $this->assertSame($futureRoot->id, $this->tree->systemRootAgent()->id);
        $this->assertSame($futureRoot->id, $this->tree->currentSponsor($orphan)->id);

        $this->actingAs($admin)
            ->get(route('admin.mlm.settings.root-agent'))
            ->assertOk()
            ->assertViewHas('orphanCount', 0);
    }

    public function test_admin_tree_page_redirects_to_the_configured_root(): void
    {
        $admin = $this->makeAdmin();
        $root = $this->makeAgent();
        $this->tree->attachAgent($root, null);
        $this->tree->setSystemRootAgent($root);

        $this->actingAs($admin)
            ->get(route('admin.mlm.tree.roots'))
            ->assertRedirect(route('admin.mlm.tree', $root));
    }

    public function test_admin_tree_page_shows_forest_when_no_root_configured_yet(): void
    {
        $admin = $this->makeAdmin();
        $orphan = $this->makeAgent();
        $this->tree->attachAgent($orphan, null);

        $this->actingAs($admin)
            ->get(route('admin.mlm.tree.roots'))
            ->assertOk()
            ->assertViewHas('roots', fn ($roots) => $roots->contains('id', $orphan->id));
    }
}
