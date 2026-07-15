<?php

namespace Tests\Feature;

use App\Models\MlmBonusPayout;
use App\Models\MlmMetricGrant;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre i "punti/agenti omaggio" (2026-07-14, richiesta di Laura): l'admin
 * seleziona uno o piu' agenti da /admin/mlm e assegna una base di punti
 * cliente e/o "Basic al 1° livello" che non scade mai (MlmMetricGrant),
 * sommata ai valori reali. Vedi MlmMetricGrantController, User::mlmActivePoints(),
 * MlmRankEngine::evaluate().
 */
class MlmMetricGrantControllerTest extends TestCase
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

    private function makeRegularUser(): User
    {
        $slug = 'reg-' . Str::random(5);

        $company = \App\Models\Company::create([
            'name'          => 'Reg Co ' . Str::random(4),
            'slug'          => $slug,
            'email'         => $slug . '@test.test',
            'status'        => 'active',
            'kyc_status'    => 'approved',
            'currency_code' => 'KY',
            'sector'        => 'informatica',
            'description'   => 'Test',
        ]);

        $user = User::create([
            'company_id'          => $company->id,
            'account_holder_type' => 'company',
            'name'                => 'Reg User',
            'email'               => 'reg-' . Str::random(6) . '@test.test',
            'password'            => 'secret123',
            'role'                => 'company-manager',
            'is_active'           => true,
            'is_super_admin'      => false,
        ]);
        $user->forceFill([
            'email_verified_at'  => now(),
            'contract_signed_at' => now(),
        ])->save();

        return $user;
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
            'valid_from'     => now()->subDay(),
            'valid_until'    => now()->addMonth(),
        ]);
    }

    public function test_store_requires_backoffice_access(): void
    {
        $user = $this->makeRegularUser();
        $agent = $this->makeAgent();

        $response = $this->actingAsWithSession($user)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'points',
            'amount'    => 12,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, MlmMetricGrant::count());
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [],
            'metric'    => 'invalid-metric',
            'amount'    => 0,
        ]);

        $response->assertSessionHasErrors(['agent_ids', 'metric', 'amount']);
    }

    public function test_granting_points_promotes_agent_to_basic_and_triggers_direct_bonuses(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->assertSame(0, $agent->mlmActivePoints());

        // 12 punti omaggio: soddisfa la soglia default di Basic (12) e TUTTE
        // le soglie dei Bonus Diretti KNM (4/6/12 punti attivi).
        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'points',
            'amount'    => 12,
            'reason'    => 'Promo lancio',
        ]);

        $response->assertRedirect(route('admin.mlm.index'));

        $grant = MlmMetricGrant::first();
        $this->assertNotNull($grant);
        $this->assertSame($agent->id, $grant->agent_user_id);
        $this->assertSame('points', $grant->metric);
        $this->assertSame(12, $grant->amount);
        $this->assertSame('Promo lancio', $grant->reason);
        $this->assertSame($admin->id, $grant->granted_by_admin_id);
        $this->assertNull($grant->revoked_at);

        $this->assertSame(12, $agent->fresh()->mlmActivePoints());
        $this->assertSame('basic', $agent->fresh()->mlm_rank);

        // Come una promozione normale (confermato da Laura): i Bonus Diretti
        // sulle 3 soglie (4/6/12) devono essere generati subito.
        $this->assertSame(3, MlmBonusPayout::where('beneficiary_user_id', $agent->id)
            ->where('kind', 'diretto')->count());
    }

    public function test_granting_level1_basic_count_promotes_agent_to_key_alongside_real_points(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 24); // soglia punti di Key (default 24)

        // Con 24 punti reali ma 0 Basic al 1° livello l'agente soddisfa gia'
        // "basic" (che non richiede Basic in downline) ma non ancora "key"
        // (richiede 2 Basic al 1° livello): li regaliamo per sbloccarlo.
        $this->assertSame('basic', app(\App\Services\MlmRankEngine::class)->evaluate($agent)['eligible_rank']);

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'level1_basic_count',
            'amount'    => 2,
        ]);

        $response->assertRedirect(route('admin.mlm.index'));

        $this->assertSame('key', $agent->fresh()->mlm_rank);
    }

    public function test_bulk_grant_applies_to_multiple_selected_agents(): void
    {
        $admin = $this->makeAdmin();
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $agentC = $this->makeAgent(); // non selezionato: non deve ricevere nulla

        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agentA->id, $agentB->id],
            'metric'    => 'points',
            'amount'    => 12,
        ])->assertRedirect(route('admin.mlm.index'));

        $this->assertSame('basic', $agentA->fresh()->mlm_rank);
        $this->assertSame('basic', $agentB->fresh()->mlm_rank);
        $this->assertSame('start', $agentC->fresh()->mlm_rank);
        $this->assertSame(2, MlmMetricGrant::count());
    }

    public function test_non_agent_ids_are_ignored(): void
    {
        $admin = $this->makeAdmin();
        $regularUser = $this->makeRegularUser();

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$regularUser->id],
            'metric'    => 'points',
            'amount'    => 12,
        ]);

        $response->assertSessionHasErrors('agent_ids');
        $this->assertSame(0, MlmMetricGrant::count());
    }

    public function test_granted_points_never_expire_but_do_not_count_before_they_were_granted(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $before = now()->subDay();

        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'points',
            'amount'    => 5,
        ]);

        $agent = $agent->fresh();

        // Mai scadenza: contano ancora fra 10 anni.
        $this->assertSame(5, $agent->mlmActivePoints(now()->addYears(10)));

        // Ma non retroattivamente prima della loro assegnazione (evita di
        // alterare il gating storico di MlmCommissionEngine per mesi passati).
        $this->assertSame(0, $agent->mlmActivePoints($before));
    }

    public function test_destroy_revokes_grant_and_can_trigger_demotion(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'points',
            'amount'    => 12,
        ]);

        $agent = $agent->fresh();
        $this->assertSame('basic', $agent->mlm_rank);
        $grant = MlmMetricGrant::where('agent_user_id', $agent->id)->firstOrFail();

        $response = $this->actingAsWithSession($admin)
            ->delete(route('admin.mlm.metric-grants.destroy', $grant));

        $response->assertRedirect();

        $grant->refresh();
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($admin->id, $grant->revoked_by_admin_id);

        $this->assertSame(0, $agent->fresh()->mlmActivePoints());
        $this->assertSame('start', $agent->fresh()->mlm_rank);
    }

    public function test_destroy_requires_backoffice_access(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'points',
            'amount'    => 12,
        ]);

        $grant = MlmMetricGrant::firstOrFail();
        $regularUser = $this->makeRegularUser();

        $response = $this->actingAsWithSession($regularUser)
            ->delete(route('admin.mlm.metric-grants.destroy', $grant));

        $response->assertForbidden();
        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_promote_form_requires_backoffice_access(): void
    {
        $user = $this->makeRegularUser();
        $agent = $this->makeAgent();

        $response = $this->actingAsWithSession($user)->get(route('admin.mlm.promote-form', $agent));

        $response->assertForbidden();
    }

    public function test_promote_form_renders_for_an_agent(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.promote-form', $agent));

        $response->assertOk()->assertSee('Assegna punti/agenti omaggio');
    }

    public function test_promote_form_404s_for_a_non_agent_user(): void
    {
        $admin = $this->makeAdmin();
        $regularUser = $this->makeRegularUser();

        $response = $this->actingAsWithSession($admin)->get(route('admin.mlm.promote-form', $regularUser));

        $response->assertNotFound();
    }

    public function test_store_with_redirect_agent_id_returns_to_the_agent_page_instead_of_the_index(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();

        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids'         => [$agent->id],
            'metric'            => 'points',
            'amount'            => 12,
            'redirect_agent_id' => $agent->id,
        ]);

        $response->assertRedirect(route('admin.mlm.show', $agent));
    }

    public function test_store_ignores_a_redirect_agent_id_not_among_the_served_agents(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $otherAgent = $this->makeAgent();

        // redirect_agent_id punta a un agente NON incluso in agent_ids: non
        // deve essere seguito (evita redirect verso una scheda a piacere).
        $response = $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids'         => [$agent->id],
            'metric'            => 'points',
            'amount'            => 12,
            'redirect_agent_id' => $otherAgent->id,
        ]);

        $response->assertRedirect(route('admin.mlm.index'));
    }

    public function test_granting_a_branch_rank_metric_promotes_agent_alongside_real_points(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $this->giveActivePoints($agent, 48); // soglia punti di Senior/Top/SuperVisor (default 48)

        // Con i punti a posto ma nessuna colonna reale con un Key+, l'agente
        // non arriva oltre "basic" finche' non regaliamo anche le colonne.
        $this->assertSame('basic', app(\App\Services\MlmRankEngine::class)->evaluate($agent)['eligible_rank']);

        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'level1_basic_count',
            'amount'    => 3,
        ]);
        $this->actingAsWithSession($admin)->post(route('admin.mlm.metric-grants.store'), [
            'agent_ids' => [$agent->id],
            'metric'    => 'branches_with_key',
            'amount'    => 2,
        ]);

        $evaluation = app(\App\Services\MlmRankEngine::class)->evaluate($agent->fresh());
        $this->assertSame(2, $evaluation['branches_with_key']);
        $this->assertSame('senior', $agent->fresh()->mlm_rank);
    }
}
