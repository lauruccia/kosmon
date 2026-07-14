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
            'activeNav'          => 'mlm-struttura',
        ])->render();

        $this->assertStringContainsString('Punti bonus assegnati', $html);
    }
}
