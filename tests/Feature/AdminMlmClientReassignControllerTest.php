<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre /admin/mlm/clienti/{user}/riassegna (2026-07-27, richiesta di Laura,
 * punto 2): form di riassegnazione + azione POST, con le stesse guardie di
 * accesso backoffice usate nel resto del pannello MLM. Vedi
 * Admin\MlmController::reassignClientForm()/reassignClient() e
 * MlmTreeService::reassignClient() (coperta a parte in MlmTreeServiceTest).
 */
class AdminMlmClientReassignControllerTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeAgent(): User
    {
        return User::create([
            'name'                => 'Agente ' . Str::random(6),
            'email'                => 'agente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'agente',
            'mlm_rank'             => 'start',
            'mlm_activated_at'     => now(),
        ]);
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

    public function test_admin_can_open_the_reassign_form_for_a_client(): void
    {
        $admin = $this->makeAdmin();
        $oldAgent = $this->makeAgent();
        $client = $this->makeClient($oldAgent);

        $response = $this->actingAs($admin)->get(route('admin.mlm.clients.reassign-form', $client));

        $response->assertOk();
        $response->assertSee($client->name);
    }

    public function test_reassign_form_404s_for_a_non_client(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $response = $this->actingAs($admin)->get(route('admin.mlm.clients.reassign-form', $agent));

        $response->assertNotFound();
    }

    public function test_non_backoffice_user_cannot_reassign_a_client(): void
    {
        // Deve superare auth/verified/twofactor/onboarding/contract per
        // arrivare fino al middleware 'backoffice' (stesso pattern di
        // MlmSettingsControllerTest::makeRegularUser()), altrimenti un
        // redirect di uno di quegli step precedenti darebbe un falso 302
        // invece del 403 che vogliamo verificare qui.
        $regularUser = User::create([
            'name'                => 'Utente normale',
            'email'                => 'utente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'is_super_admin'       => false,
        ]);
        $regularUser->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();
        $newAgent = $this->makeAgent();
        $client = $this->makeClient();

        $response = $this->actingAs($regularUser)
            ->post(route('admin.mlm.clients.reassign', $client), ['new_agent_id' => $newAgent->id]);

        $response->assertForbidden();
        $this->assertNull($client->fresh()->mlm_client_agent_id);
    }

    public function test_admin_can_reassign_a_client_to_a_new_agent(): void
    {
        $admin = $this->makeAdmin();
        $oldAgent = $this->makeAgent();
        $newAgent = $this->makeAgent();
        $client = $this->makeClient($oldAgent);

        $response = $this->actingAs($admin)
            ->post(route('admin.mlm.clients.reassign', $client), ['new_agent_id' => $newAgent->id]);

        $response->assertRedirect(route('admin.mlm.show', $newAgent));
        $this->assertSame($newAgent->id, $client->fresh()->mlm_client_agent_id);
    }

    public function test_admin_can_unassign_a_client(): void
    {
        $admin = $this->makeAdmin();
        $oldAgent = $this->makeAgent();
        $client = $this->makeClient($oldAgent);

        $response = $this->actingAs($admin)
            ->post(route('admin.mlm.clients.reassign', $client), ['new_agent_id' => '']);

        $response->assertRedirect(route('admin.mlm.index'));
        $this->assertNull($client->fresh()->mlm_client_agent_id);
    }
}
