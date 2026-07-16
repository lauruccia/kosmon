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
 * Copre MlmPointsService: durate normali di produzione (1/12/24/36 mesi a
 * seconda dello scaglione, vedi mlm_piano.xlsx foglio "PC PUNTI CLIENTI") e
 * l'override di test (SystemSetting::mlmSettings()->mlm_points_validity_override_minutes,
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

        $this->assertSame(1, $entry->points);
        $this->assertSame('registration', $entry->source_type);
        // "Fine giornata" (endOfDay), non l'istante esatto +1 mese: preserva
        // il comportamento storico pre-2026-07-13 basato su DATE/whereDate().
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonth()));
        $this->assertSame(23, $entry->valid_until->hour);
        $this->assertSame(1, $agent->mlmActivePoints());
    }

    public function test_deposit_tiers_award_correct_points_and_duration(): void
    {
        $agent = $this->makeAgent();

        // Scaglione 1.200 EUR -> 2 punti/mese per 12 mesi (24 punti totali).
        $client = $this->makeClient($agent);
        $this->service->awardDepositPoints($client, 120_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertSame(2, $entry->points);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addMonths(12)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(10_000, $baseLedger->monthly_amount_eur_cents); // 120.000 / 12 mesi
        // Snapshot del margine KNM ("Prov K") al momento del deposito
        // (default 30%, slide "Esempio compensi" — 2026-07-16).
        $this->assertSame(30, $baseLedger->knm_margin_percent);
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

        // Anche uno scaglione da 36 mesi deve rispettare l'override di 1 ora.
        $this->service->awardDepositPoints($client, 360_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();

        $this->assertTrue($entry->valid_until->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertSame(2, $agent->mlmActivePoints());

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
