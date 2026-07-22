<?php

namespace Tests\Feature;

use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Distribuzione punti per ramo/colonna (2026-07-22, richiesta di Laura):
 * - admin/mlm/show: colonna "Verso i 300 pt" con barra e punti mancanti;
 * - portale "La mia struttura": tabella "Le tue colonne / rami";
 * - albero (partial condiviso): badge "Ramo: X pt" sui figli diretti della
 *   radice visualizzata e data-branch-points su ogni nodo (per il popup).
 */
class MlmBranchPointsViewTest extends TestCase
{
    use RefreshDatabase;

    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = app(MlmTreeService::class);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'                => 'Agente ' . Str::random(6),
            'email'               => 'agente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'agente',
            'mlm_rank'            => 'start',
            'mlm_activated_at'    => now(),
        ], $overrides));
    }

    private function givePoints(User $agent, float $points): void
    {
        $client = User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'               => 'cliente-' . Str::random(10) . '@test.test',
            'password'            => 'secret123',
            'account_holder_type' => 'private',
            'company_id'          => null,
            'is_active'           => true,
            'mlm_role'            => 'cliente',
            'mlm_client_agent_id' => $agent->id,
        ]);

        MlmPointLedgerEntry::create([
            'agent_user_id' => $agent->id,
            'client_user_id' => $client->id,
            'source_type'   => 'registration',
            'points'        => $points,
            'valid_from'    => now()->subMonth()->toDateString(),
            'valid_until'   => now()->addMonth()->toDateString(),
        ]);
    }

    /**
     * Struttura di test: root ── colonnaPiena(320) e root ── colonnaMezza(120).
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function buildStructure(): array
    {
        $root = $this->makeUser(['mlm_rank' => 'key', 'name' => 'Radice Test']);
        $full = $this->makeUser(['mlm_rank' => 'basic', 'name' => 'Colonna Piena']);
        $half = $this->makeUser(['mlm_rank' => 'basic', 'name' => 'Colonna Mezza']);

        $this->tree->attachAgent($root, null);
        $this->tree->attachAgent($full, $root);
        $this->tree->attachAgent($half, $root);

        $this->givePoints($full, 320);
        $this->givePoints($half, 120);

        return [$root, $full, $half];
    }

    public function test_admin_show_renders_progress_towards_300_per_branch(): void
    {
        [$root] = $this->buildStructure();

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-' . Str::random(10) . '@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true, 'is_super_admin' => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.show', $root));

        $response->assertOk()
            ->assertSee('Verso i 300 pt')
            // Colonna sopra soglia: spunta verde.
            ->assertSee('300 raggiunti')
            // Colonna sotto soglia: 120/300, ne mancano 180.
            ->assertSee('ne mancano 180');
    }

    public function test_portal_struttura_renders_the_branch_table_with_missing_points(): void
    {
        [$root] = $this->buildStructure();

        $this->actingAs($root);
        $this->withViewErrors([]);
        $html = view('portal.mlm.struttura', [
            'pageTitle'          => 'La mia struttura',
            'tree'               => $this->tree->subtree($root),
            'branches'           => $this->tree->branchSummaries($root),
            'agent'              => $root,
            'activePoints'       => $root->mlmActivePoints(),
            'expiringPoints'     => 0,
            'rankAtRisk'         => false,
            'grantedPoints'      => 0,
            'grantedLevel1Basic' => 0,
            'nextRank'           => app(MlmRankEngine::class)->nextRankRequirements($root),
            'retention'          => null,
            'activeNav'          => 'mlm-struttura',
        ])->render();

        $this->assertStringContainsString('Le tue colonne / rami', $html);
        $this->assertStringContainsString('Colonna Piena', $html);
        $this->assertStringContainsString('300 raggiunti', $html);
        $this->assertStringContainsString('ne mancano 180', $html);
        // La vista resta retrocompatibile senza $branches (es. smoke test
        // esistente): nessuna tabella, nessun errore.
        $htmlNoBranches = view('portal.mlm.struttura', [
            'pageTitle'          => 'La mia struttura',
            'tree'               => [],
            'agent'              => $root,
            'activePoints'       => 0,
            'expiringPoints'     => 0,
            'rankAtRisk'         => false,
            'grantedPoints'      => 0,
            'grantedLevel1Basic' => 0,
            'nextRank'           => null,
            'retention'          => null,
            'activeNav'          => 'mlm-struttura',
        ])->render();
        $this->assertStringNotContainsString('Le tue colonne / rami', $htmlNoBranches);
    }

    public function test_tree_partial_shows_branch_badge_on_first_level_and_branch_points_on_every_node(): void
    {
        [$root, $full, $half] = $this->buildStructure();

        // Un nipote sotto "Colonna Mezza" per verificare che il badge NON
        // compaia ai livelli piu' profondi ma il data-branch-points si'.
        $grandchild = $this->makeUser(['name' => 'Nipote Test']);
        $this->tree->attachAgent($grandchild, $half);
        $this->givePoints($grandchild, 30);

        $this->actingAs($root);
        $html = view('partials.mlm-tree', [
            'tree' => $this->tree->subtree($root),
            'mode' => 'portal',
        ])->render();

        // Badge solo sulle colonne (figli diretti della radice visualizzata):
        // 320 per la piena, 150 (120+30) per la mezza. Il nipote (30 pt) non
        // ha badge ma espone comunque il suo branch_points nel data-attribute.
        $this->assertStringContainsString('Ramo: 320 pt', $html);
        $this->assertStringContainsString('Ramo: 150 pt', $html);
        $this->assertSame(2, substr_count($html, 'class="mlm-branch-badge"'), 'Il badge compare solo sui rami di 1° livello.');
        $this->assertStringContainsString('data-branch-points="30"', $html);
        // Popup: riga "Punti ramo" presente.
        $this->assertStringContainsString('Punti ramo', $html);
        // La radice espone il totale complessivo (0 propri + 320 + 150).
        $this->assertStringContainsString('data-branch-points="470"', $html);
    }
}
