<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre la richiesta di Laura del 2026-07-31 (dopo il primo rilascio del
 * contratto agente compilato): "deve visualizzare prima il contratto agente
 * bloccante finché non lo firma e solo dopo quello kmoney" — un utente in
 * stato mlmAgentAwaitingContract() deve essere bloccato su QUALUNQUE altra
 * pagina del portale (incluso il contratto di adesione generale KMoney) fino
 * a quando non firma il contratto di nomina agente.
 *
 * Vedi App\Http\Middleware\EnsureMlmAgentContractSigned (alias
 * 'agent.contract' in bootstrap/app.php, applicato prima di 'contract' nello
 * stack — vedi routes/web.php).
 */
class MlmAgentContractGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Il dashboard del portale (PortalController::resolveCurrentContext())
     * richiede sempre un Account risolvibile per l'utente corrente
     * (altrimenti 404, come per qualunque utente reale che si registra —
     * vedi MlmPortalController::registraAgenteStore()): i test che
     * arrivano fino al controller (cioè non vengono bloccati/rediretti
     * prima dal middleware oggetto di questo test) devono averne uno.
     */
    private function makeAccountFor(User $user): Account
    {
        return Account::create([
            'owner_user_id'          => $user->id,
            'owner_type'             => 'private',
            'type'                   => 'primary',
            'account_name'           => 'Conto personale ' . $user->name,
            'currency_code'          => 'KY',
            'status'                 => 'active',
            'allow_negative_balance' => false,
            'available_balance'      => 0,
            'pending_balance'        => 0,
        ]);
    }

    private function makeAwaitingAgent(): User
    {
        $user = User::create([
            'name'                     => 'Agente In Attesa',
            'email'                    => 'attesa-' . Str::random(10) . '@test.test',
            'password'                 => 'secret123',
            'account_holder_type'      => 'private',
            'is_active'                => true,
            'mlm_role'                 => 'cliente',
            'mlm_agent_request_status' => 'approved',
            'mlm_agent_reviewed_at'    => now(),
            // 2026-08-14: senza anagrafica completa la firma è bloccata
            // (User::missingAgentContractFields()). Qui si testa il gate del
            // middleware, non la compilazione dei dati: quella è coperta da
            // MlmAgentRequestFlowTest.
            'fiscal_code'              => strtoupper(Str::random(16)),
            'birth_date'               => '1985-08-01',
            'birth_place'              => 'Roma',
            'residence_address'        => 'Via Roma 10',
            'residence_zip'            => '00100',
            'residence_city'           => 'Roma',
            'residence_province'       => 'RM',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->makeAccountFor($user);

        return $user;
    }

    public function test_an_agent_awaiting_contract_is_redirected_to_the_agent_contract_from_any_portal_page(): void
    {
        $user = $this->makeAwaitingAgent();
        $this->assertTrue($user->mlmAgentAwaitingContract());

        $response = $this->actingAsWithSession($user)->get(route('portal.dashboard'));

        $response->assertRedirect(route('portal.mlm.agent-contract.show'));
    }

    public function test_an_agent_awaiting_contract_can_still_reach_the_agent_contract_routes_directly(): void
    {
        Notification::fake();
        $user = $this->makeAwaitingAgent();

        $this->actingAsWithSession($user)->get(route('portal.mlm.agent-contract.show'))->assertOk();
        $this->actingAsWithSession($user)->post(route('portal.mlm.agent-contract.send-otp'))->assertRedirect(route('portal.mlm.agent-contract.show'));
    }

    public function test_a_regular_client_is_not_affected_by_the_agent_contract_gate(): void
    {
        $user = User::create([
            'name'  => 'Cliente Normale',
            'email' => 'normale-' . Str::random(10) . '@test.test',
            'password' => 'secret123',
            'account_holder_type' => 'private',
            'is_active' => true,
            'mlm_role' => 'cliente',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->makeAccountFor($user);

        $response = $this->actingAsWithSession($user)->get(route('portal.dashboard'));

        $response->assertOk();
    }

    public function test_after_signing_the_agent_contract_the_dashboard_becomes_reachable(): void
    {
        Notification::fake();
        $user = $this->makeAwaitingAgent();

        $this->actingAsWithSession($user)->post(route('portal.mlm.agent-contract.send-otp'));
        $otp = $user->fresh()->mlm_agent_contract_otp;

        $this->actingAsWithSession($user->fresh())
            ->post(route('portal.mlm.agent-contract.sign'), ['otp' => $otp])
            ->assertRedirect(route('portal.mlm.struttura'));

        $response = $this->actingAsWithSession($user->fresh())->get(route('portal.dashboard'));

        $response->assertOk();
    }
}
