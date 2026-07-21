<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MlmMetricGrantSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_index_show_and_portal_struttura_render_ok(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.Str::random(10).'@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true, 'is_super_admin' => true,
        ]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $agent = User::create([
            'name' => 'Agente', 'email' => 'agente-'.Str::random(10).'@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true,
            'mlm_role' => 'agente', 'mlm_rank' => 'start', 'mlm_activated_at' => now(),
        ]);

        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $agent->id,
            'metric' => 'points',
            'amount' => 5,
            'granted_by_admin_id' => $admin->id,
        ]);

        $this->actingAsWithSession($admin)->get(route('admin.mlm.index'))->assertOk()->assertSee('omaggio');
        $this->actingAsWithSession($admin)->get(route('admin.mlm.show', $agent))->assertOk()->assertSee('Punti/agenti omaggio');

        // Rendering diretto della vista portale (senza passare dallo stack di
        // middleware auth/onboarding/contract, non rilevante per questo
        // smoke test): verifica solo che il Blade nuovo compili e mostri il
        // badge "omaggio" quando ci sono punti/agenti regalati.
        $html = view('portal.mlm.struttura', [
            'pageTitle'          => 'La mia struttura',
            'tree'               => [],
            'agent'              => $agent,
            'activePoints'       => $agent->mlmActivePoints(),
            'expiringPoints'     => 0,
            'rankAtRisk'         => false,
            'grantedPoints'      => $agent->mlmGrantedPoints(),
            'grantedLevel1Basic' => $agent->mlmGrantedLevel1Basic(),
            'nextRank'           => app(\App\Services\MlmRankEngine::class)->nextRankRequirements($agent),
            'activeNav'          => 'mlm-struttura',
        ])->render();

        $this->assertStringContainsString('Punti bonus assegnati', $html);
        // Checklist "verso la prossima qualifica" (2026-07-21): l'agente
        // start vede cosa gli manca per Basic.
        $this->assertStringContainsString('Verso la qualifica Basic', $html);
        $this->assertStringContainsString('Punti attivi', $html);
        // Scomposizione reale+omaggio nei chip (2026-07-21): l'agente ha
        // 0 punti reali e 5 omaggio.
        $this->assertStringContainsString('(0 reali + 5 omaggio)', $html);
    }

    public function test_tree_partial_shows_granted_points_only_to_admin_or_owner(): void
    {
        $tree = app(\App\Services\MlmTreeService::class);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.Str::random(10).'@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true, 'is_super_admin' => true,
        ]);

        $agentA = User::create([
            'name' => 'Agente A', 'email' => 'agente-a-'.Str::random(10).'@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true,
            'mlm_role' => 'agente', 'mlm_rank' => 'key', 'mlm_activated_at' => now(),
        ]);
        $agentB = User::create([
            'name' => 'Agente B', 'email' => 'agente-b-'.Str::random(10).'@test.test',
            'password' => 'secret123', 'account_holder_type' => 'private',
            'company_id' => null, 'is_active' => true,
            'mlm_role' => 'agente', 'mlm_rank' => 'basic', 'mlm_activated_at' => now(),
        ]);

        $tree->attachAgent($agentA, null);
        $tree->attachAgent($agentB, $agentA);

        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $agentA->id, 'metric' => 'points',
            'amount' => 10, 'granted_by_admin_id' => $admin->id,
        ]);
        \App\Models\MlmMetricGrant::create([
            'agent_user_id' => $agentB->id, 'metric' => 'points',
            'amount' => 30, 'granted_by_admin_id' => $admin->id,
        ]);

        $subtree = $tree->subtree($agentA);

        // Portale, loggata come A: vede i PROPRI +10, non i +30 di B.
        $this->actingAs($agentA);
        $html = view('partials.mlm-tree', ['tree' => $subtree, 'mode' => 'portal'])->render();
        $this->assertStringContainsString('data-granted="+10"', $html);
        $this->assertStringNotContainsString('data-granted="+30"', $html);

        // Admin: vede tutto.
        $this->actingAs($admin);
        $html = view('partials.mlm-tree', ['tree' => $subtree, 'mode' => 'admin'])->render();
        $this->assertStringContainsString('data-granted="+10"', $html);
        $this->assertStringContainsString('data-granted="+30"', $html);
    }
}
