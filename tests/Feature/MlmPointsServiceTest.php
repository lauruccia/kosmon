<?php

namespace Tests\Feature;

use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MlmPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre MlmPointsService con la regola "/12" della slide (2026-07-20,
 * decisione di Laura "sempre /12 come slide", che sostituisce gli scaglioni
 * 1/12/24/36 mesi del foglio mlm_piano.xlsx): ogni deposito sopra la soglia
 * minima (120 EUR) genera importo mensile = deposito/12 per 12 mesi e punti
 * FRAZIONARI = importo mensile / 50 EUR (slide "Importo Personale Mensile").
 * Copre anche l'override di test
 * (SystemSetting::mlmSettings()->mlm_points_validity_override_minutes,
 * introdotto il 2026-07-13 per permettere scadenze brevi — es. 1 ora — invece
 * di aspettare mesi). Vedi anche MlmRankEngineTest per l'effetto a valle sul
 * calcolo qualifiche.
 */
class MlmPointsServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmPointsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MlmPointsService();
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

    public function test_registration_points_are_valid_for_one_month_in_production(): void
    {
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardRegistrationPoints($client);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();

        $this->assertEqualsWithDelta(1.0, $entry->points, 0.001);
        $this->assertSame('registration', $entry->source_type);
        // "Fine giornata" (endOfDay), non l'istante esatto +1 mese: preserva
        // il comportamento storico pre-2026-07-13 basato su DATE/whereDate().
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonth()));
        $this->assertSame(23, $entry->valid_until->hour);
        $this->assertSame(1, $agent->mlmActivePoints());
    }

    public function test_deposit_follows_the_divide_by_12_slide_rule(): void
    {
        $agent = $this->makeAgent();

        // 1.200 EUR -> 100 EUR/mese per 12 mesi -> 2 punti/mese (100/50).
        $client = $this->makeClient($agent);
        $this->service->awardDepositPoints($client, 120_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonths(12)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(10_000, $baseLedger->monthly_amount_eur_cents); // 120.000 / 12 mesi
        // Snapshot del margine KNM ("Prov K") al momento del deposito
        // (default 30%, slide "Esempio compensi" — 2026-07-16).
        $this->assertSame(30, $baseLedger->knm_margin_percent);
    }

    public function test_small_deposit_generates_fractional_points_over_12_months(): void
    {
        // Slide "Importo Personale Mensile": 120 EUR -> 10 EUR/mese per 12
        // mesi -> 0,2 punti/mese. Prima del 2026-07-20 lo scaglione minimo
        // valeva 1 punto per UN solo mese: la regola "/12" della slide vale
        // per qualunque importo.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(0.2, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonths(12)));
        $this->assertEqualsWithDelta(0.2, $agent->mlmActivePoints(), 0.001);

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(1_000, $baseLedger->monthly_amount_eur_cents); // 12.000 / 12 mesi
        $this->assertTrue(\Illuminate\Support\Carbon::parse($baseLedger->valid_until)->isSameDay(now()->addMonths(12)));
    }

    public function test_large_deposits_also_divide_by_12_with_proportional_points(): void
    {
        // 2.400 EUR -> 200 EUR/mese per 12 mesi -> 4 punti/mese (200/50).
        // Prima del 2026-07-20 lo scaglione xlsx dava 2 punti per 24 mesi.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 240_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(4.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonths(12)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(20_000, $baseLedger->monthly_amount_eur_cents); // 240.000 / 12 mesi
        $this->assertSame(4, $agent->mlmActivePoints());
    }

    public function test_fractional_points_from_multiple_clients_add_up(): void
    {
        // Due depositi da 120 EUR (0,2 punti l'uno) + uno da 1.200 EUR
        // (2 punti): totale 2,4 punti attivi, frazionari e sommabili.
        $agent = $this->makeAgent();

        $this->service->awardDepositPoints($this->makeClient($agent), 12_000);
        $this->service->awardDepositPoints($this->makeClient($agent), 12_000);
        $this->service->awardDepositPoints($this->makeClient($agent), 120_000);

        $this->assertEqualsWithDelta(2.4, $agent->mlmActivePoints(), 0.001);
        $this->assertSame('2,4', mlm_points_format($agent->mlmActivePoints()));
    }

    public function test_deposit_snapshots_the_current_knm_margin(): void
    {
        // Cambiando il margine in admin, i NUOVI depositi fotografano il
        // valore corrente (i vecchi mantengono il loro, vedi
        // MlmCommissionEngineTest::test_per_row_margin_snapshot_wins_over_the_current_setting).
        SystemSetting::mlmSettings()->forceFill(['mlm_knm_margin_percent' => 10])->save();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);
        $this->service->awardDepositPoints($client, 120_000);

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(10, $baseLedger->knm_margin_percent);
    }

    public function test_deposit_below_minimum_threshold_awards_no_points(): void
    {
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 5_000); // 50 EUR, sotto soglia 120 EUR

        $this->assertSame(0, MlmPointLedgerEntry::where('agent_user_id', $agent->id)->count());
        $this->assertSame(0, $agent->mlmActivePoints());
    }

    public function test_points_validity_override_forces_a_short_expiry(): void
    {
        SystemSetting::mlmSettings()->forceFill(['mlm_points_validity_override_minutes' => 60])->save();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        // Anche un deposito grande (3.600 EUR -> 300 EUR/mese -> 6 punti)
        // deve rispettare l'override di 1 ora.
        $this->service->awardDepositPoints($client, 360_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();

        $this->assertTrue($entry->valid_until->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(6, $agent->mlmActivePoints());

        // Punti ancora attivi "adesso" ma scaduti se valutati fra 61 minuti.
        $this->assertSame(0, $agent->mlmActivePoints(now()->addMinutes(61)));
    }

    public function test_points_validity_override_is_ignored_when_null(): void
    {
        SystemSetting::mlmSettings()->forceFill(['mlm_points_validity_override_minutes' => null])->save();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 120_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonths(12)));
    }

    public function test_registration_points_ignore_users_without_a_resolved_agent(): void
    {
        $orphan = User::create([
            'name'                => 'Orfano',
            'email'                => 'orfano-' . Str::random(10) . '@test.test',
            'password'             => 'secret123',
            'account_holder_type'  => 'private',
            'company_id'           => null,
            'is_active'            => true,
            'mlm_role'             => 'cliente',
            'mlm_client_agent_id'  => null,
        ]);

        $this->service->awardRegistrationPoints($orphan);

        $this->assertSame(0, MlmPointLedgerEntry::count());
    }
}
