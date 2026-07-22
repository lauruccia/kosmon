<?php

namespace Tests\Feature;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\MlmCommission;
use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmCommissionRun;
use App\Models\MlmPointLedgerEntry;
use App\Models\User;
use App\Services\MlmSimulationService;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre il simulatore compensi admin (2026-07-21): MlmSimulationService
 * (ricarica cliente -> punti + delta commissioni; evento BasiQ -> cascata
 * bonus annotata) e la pagina /admin/mlm-simulatore. La garanzia centrale
 * verificata qui e' che le simulazioni producono i numeri dei motori REALI
 * senza lasciare NULLA scritto nel database (rollback totale).
 */
class MlmSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private MlmSimulationService $simulator;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->simulator = app(MlmSimulationService::class);

        // I tagli di ricarica sono KY Card reali (2026-07-22): il simulatore
        // risolve la card dal prezzo, quindi i test le seedano.
        foreach ([[12_000, 2, 30], [60_000, 2, 180], [120_000, 2, 360]] as [$price, $points, $days]) {
            \App\Models\KyCard::create([
                'name' => 'Card ' . $price, 'price_eur_cents' => $price,
                'bonus_type' => 'fixed', 'ky_base_amount' => $price, 'bonus_value' => 0,
                'mlm_points' => $points, 'mlm_points_duration_days' => $days,
            ]);
        }
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

    private function makeClient(User $agent): User
    {
        return User::create([
            'name'                => 'Cliente ' . Str::random(6),
            'email'                => 'cliente-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => $agent->id,
        ]);
    }

    /** Punti attivi "a mano" per pilotare percentuale diretta e gating (stesso pattern degli altri test MLM). */
    private function givePoints(User $agent, int|float $points): void
    {
        MlmPointLedgerEntry::create([
            'agent_user_id'  => $agent->id,
            'client_user_id' => $this->makeClient($agent)->id,
            'source_type'    => 'registration',
            'points'         => $points,
            'valid_from'     => now()->subDay(),
            'valid_until'    => now()->addYear(),
        ]);
    }

    // ── Simulazione ricarica ────────────────────────────────────────────────

    public function test_deposit_simulation_reports_points_base_and_direct_commission(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 12); // 12 pt -> diretta 10%
        $client = $this->makeClient($agent);

        // 1.200 EUR -> 2 punti (taglio 1.200, tabella mlm_point_rules);
        // base una tantum = intero importo; Prov K = 1.200 x 30% = 360 EUR;
        // diretta 10% (l'agente ha 12 pt) = 36 EUR, una sola volta.
        $result = $this->simulator->simulateDeposit($client, 120_000);

        $this->assertCount(1, $result['ledger_entries']);
        $this->assertSame(2, $result['ledger_entries'][0]['points']);
        $this->assertSame($agent->name, $result['ledger_entries'][0]['agent_name']);

        $this->assertCount(1, $result['base_entries']);
        $this->assertSame(120_000, $result['base_entries'][0]['monthly_amount_eur_cents']);
        $this->assertSame(30, $result['base_entries'][0]['knm_margin_percent']);
        $this->assertSame(36_000, $result['base_entries'][0]['prov_k_eur_cents']);

        $direct = collect($result['commissions'])->firstWhere('type', 'diretta');
        $this->assertNotNull($direct);
        $this->assertSame($agent->id, $direct['agent_id']);
        $this->assertSame(36_000, $direct['base_amount_eur_cents']);
        $this->assertSame(3_600, $direct['amount_eur_cents']);
        $this->assertSame(3_600, $result['total_commissions_eur_cents']);
    }

    public function test_deposit_simulation_includes_indirect_commissions_for_the_upline(): void
    {
        // sponsor (48 pt, 1 Basic diretto) sopra agent (12 pt): la ricarica di
        // un cliente di agent genera la diretta di agent E l'indiretta di 1°
        // livello (4%) dello sponsor.
        $sponsor = $this->makeAgent('key');
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($sponsor, null);
        $this->tree->attachAgent($agent, $sponsor);
        $this->givePoints($sponsor, 48);
        $this->givePoints($agent, 12);
        $client = $this->makeClient($agent);

        $result = $this->simulator->simulateDeposit($client, 120_000);

        $indirect = collect($result['commissions'])->firstWhere('type', 'indiretta');
        $this->assertNotNull($indirect);
        $this->assertSame($sponsor->id, $indirect['agent_id']);
        $this->assertSame(1, $indirect['level']);
        $this->assertSame(36_000, $indirect['base_amount_eur_cents']);
        $this->assertSame(1_440, $indirect['amount_eur_cents']); // 360 EUR x 4%
    }

    public function test_deposit_simulation_delta_excludes_commissions_from_preexisting_deposits(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 12);
        $client = $this->makeClient($agent);

        // Deposito PRE-esistente gia' attivo: le sue commissioni non devono
        // comparire nel delta della nuova ricarica simulata.
        app(\App\Services\MlmPointsService::class)->awardDepositPoints($client, 240_000);

        $result = $this->simulator->simulateDeposit($client, 120_000);

        // Solo l'effetto della nuova ricarica: base Prov K 360 EUR, diretta
        // 10% = 36 EUR (senza il deposito preesistente da 240k).
        $direct = collect($result['commissions'])->firstWhere('type', 'diretta');
        $this->assertNotNull($direct);
        $this->assertSame(36_000, $direct['base_amount_eur_cents']);
        $this->assertSame(3_600, $direct['amount_eur_cents']);
    }

    public function test_deposit_simulation_below_threshold_yields_nothing(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 12);
        $client = $this->makeClient($agent);

        $result = $this->simulator->simulateDeposit($client, 11_999); // < 120 EUR

        $this->assertSame([], $result['ledger_entries']);
        $this->assertSame([], $result['base_entries']);
        $this->assertSame([], $result['commissions']);
        $this->assertSame(0, $result['total_commissions_eur_cents']);
    }

    public function test_deposit_simulation_writes_nothing_to_the_database(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 12);
        $client = $this->makeClient($agent);

        $ledgerBefore = MlmPointLedgerEntry::count();
        $baseBefore = MlmCommissionBaseLedgerEntry::count();

        $this->simulator->simulateDeposit($client, 120_000);

        $this->assertSame($ledgerBefore, MlmPointLedgerEntry::count());
        $this->assertSame($baseBefore, MlmCommissionBaseLedgerEntry::count());
        $this->assertSame(0, MlmCommission::count());
        $this->assertSame(0, MlmCommissionRun::count());
    }

    // ── Simulazione BasiQ ───────────────────────────────────────────────────

    public function test_basiq_simulation_reports_the_positional_cascade_with_notes(): void
    {
        // senior sopra key NON eleggibile sopra basiq: il Key e' assente
        // (0 eventi pregressi), il Senior incassa 110 pieni.
        $senior = $this->makeAgent('senior');
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($key, $senior);
        $this->tree->attachAgent($basiq, $key);

        $result = $this->simulator->simulateBasiq($basiq);

        $this->assertCount(2, $result['chain']);

        [$keyRow, $seniorRow] = $result['chain'];

        $this->assertSame('key', $keyRow['rank']);
        $this->assertSame(0, $keyRow['payout_eur_cents']);
        $this->assertStringContainsString('non ancora eleggibile', $keyRow['note']);

        $this->assertSame('senior', $seniorRow['rank']);
        $this->assertSame(11_000, $seniorRow['payout_eur_cents']);

        $this->assertSame(11_000, $result['total_eur_cents']);
    }

    public function test_basiq_simulation_can_resimulate_an_agent_with_an_existing_event(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        // Evento reale gia' registrato (vincolo unique per agente): la
        // simulazione deve comunque funzionare e non toccarlo.
        $existing = MlmBonusEvent::create([
            'basiq_user_id'         => $basiq->id,
            'triggered_at'          => now()->subDays(30),
            'status'                => 'processed',
            'processed_at'          => now()->subDays(30),
            'upline_chain_snapshot' => [],
        ]);

        $result = $this->simulator->simulateBasiq($basiq);

        $this->assertSame(11_000, $result['total_eur_cents']);

        // L'evento storico e' ancora li', intatto.
        $this->assertDatabaseHas('mlm_bonus_events', [
            'id' => $existing->id,
            'basiq_user_id' => $basiq->id,
            'status' => 'processed',
        ]);
        $this->assertSame(1, MlmBonusEvent::count());
        $this->assertSame(0, MlmBonusPayout::count());
    }

    public function test_basiq_simulation_writes_nothing_to_the_database(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        $this->simulator->simulateBasiq($basiq);

        $this->assertSame(0, MlmBonusEvent::count());
        $this->assertSame(0, MlmBonusPayout::count());
    }

    // ── Pagina admin ────────────────────────────────────────────────────────

    public function test_admin_can_view_the_simulator_page(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $client = $this->makeClient($agent);

        $response = $this->actingAs($this->makeAdmin())->get(route('admin.mlm.simulator.show'));

        $response->assertOk()
            ->assertSee('Simulatore compensi')
            ->assertSee($client->name)
            ->assertSee($agent->name);
    }

    public function test_non_admin_cannot_access_the_simulator(): void
    {
        $agent = $this->makeAgent('basic');
        $agent->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($agent)->get(route('admin.mlm.simulator.show'))->assertForbidden();
    }

    public function test_deposit_endpoint_renders_the_result(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);
        $this->givePoints($agent, 12);
        $client = $this->makeClient($agent);

        $response = $this->actingAs($this->makeAdmin())->post(route('admin.mlm.simulator.deposit'), [
            'client_id' => $client->id,
            'amount_eur' => '1200',
        ]);

        $response->assertOk()
            ->assertSee('Risultato — ricarica di 1.200,00')
            ->assertSee('Base commissionabile')
            ->assertSee('nessun dato è stato salvato', false);

        $this->assertSame(0, MlmCommission::count());
        $this->assertSame(1, MlmPointLedgerEntry::count()); // solo la riga dei 12 pt di setup: la simulazione non ha scritto nulla
    }

    public function test_deposit_endpoint_rejects_a_non_client_user(): void
    {
        $agent = $this->makeAgent('basic');
        $this->tree->attachAgent($agent, null);

        $response = $this->actingAs($this->makeAdmin())->post(route('admin.mlm.simulator.deposit'), [
            'client_id' => $agent->id,
            'amount_eur' => '1200',
        ]);

        $response->assertOk()->assertSee('non e', false);
        $this->assertSame(0, MlmCommission::count());
    }

    public function test_basiq_endpoint_renders_the_cascade(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        $response = $this->actingAs($this->makeAdmin())->post(route('admin.mlm.simulator.basiq'), [
            'agent_id' => $basiq->id,
        ]);

        $response->assertOk()
            ->assertSee('diventa BasiQ')
            ->assertSee($senior->name)
            ->assertSee('110,00');

        $this->assertSame(0, MlmBonusEvent::count());
        $this->assertSame(0, MlmBonusPayout::count());
    }
}
