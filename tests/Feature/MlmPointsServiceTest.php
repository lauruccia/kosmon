<?php

namespace Tests\Feature;

use App\Models\MlmCommissionBaseLedgerEntry;
use App\Models\MlmPointLedgerEntry;
use App\Models\MlmPointRule;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MlmPointsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre MlmPointsService con la tabella "punti per evento" (2026-07-22,
 * decisione di Laura — sostituisce la regola "/12 + frazionari" del
 * 2026-07-20): i punti NON vengono piu' spalmati su 12 mesi ma maturano nel
 * momento della ricarica, secondo la riga di mlm_point_rules col taglio piu'
 * alto <= importo (seed: 120 EUR -> 2 pt / 30 gg, 600 EUR -> 2 pt / 180 gg,
 * 1.200 EUR -> 2 pt / 360 gg; apertura conto 1 pt / 90 gg). Anche la base
 * commissionabile e' una tantum: intero importo, finestra = il solo 1° del
 * mese successivo (vedi MlmCommissionEngineTest per il run che la paga).
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

    public function test_registration_awards_one_point_for_ninety_days_from_the_seeded_rule(): void
    {
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardRegistrationPoints($client);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();

        $this->assertEqualsWithDelta(1.0, $entry->points, 0.001);
        $this->assertSame('registration', $entry->source_type);
        // "Fine giornata" (endOfDay), non l'istante esatto +90 giorni: preserva
        // il comportamento storico pre-2026-07-13 basato su DATE/whereDate().
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(90)));
        $this->assertSame(23, $entry->valid_until->hour);
        $this->assertSame(1, $agent->mlmActivePoints());
    }

    public function test_deposit_awards_the_points_of_the_matching_tier_without_spreading(): void
    {
        $agent = $this->makeAgent();

        // 1.200 EUR -> taglio 1.200: 2 punti SUBITO, validi 360 giorni.
        // Niente piu' /12: la base commissionabile e' l'intero importo.
        $client = $this->makeClient($agent);
        $this->service->awardDepositPoints($client, 120_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(360)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(120_000, $baseLedger->monthly_amount_eur_cents); // intero importo, una tantum
        // Snapshot del margine KNM ("Prov K") al momento del deposito
        // (default 30%, slide "Esempio compensi" — 2026-07-16).
        $this->assertSame(30, $baseLedger->knm_margin_percent);
    }

    public function test_minimum_tier_deposit_awards_two_points_for_thirty_days(): void
    {
        // 120 EUR -> taglio minimo: 2 punti per 30 giorni ("1 mese = 30
        // giorni", decisione 2026-07-22). Prima: 0,2 punti/mese per 12 mesi.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(30)));
        $this->assertSame(2, $agent->mlmActivePoints());

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(12_000, $baseLedger->monthly_amount_eur_cents);
    }

    public function test_middle_tier_deposit_awards_two_points_for_one_hundred_eighty_days(): void
    {
        // 600 EUR -> taglio 600: 2 punti per 180 giorni.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 60_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(180)));
    }

    public function test_off_tier_amount_falls_back_to_the_highest_tier_below_it(): void
    {
        // 800 EUR non e' un taglio: si applica il taglio piu' alto <= importo,
        // cioe' 600 EUR (2 punti / 180 giorni). La base commissionabile resta
        // l'importo REALE della ricarica (800 EUR), non il taglio.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 80_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(180)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(80_000, $baseLedger->monthly_amount_eur_cents);
    }

    public function test_amount_above_the_highest_tier_uses_the_highest_tier(): void
    {
        // 2.400 EUR: nessun taglio dedicato (finche' l'admin non lo aggiunge),
        // si applica il taglio 1.200 (2 punti / 360 giorni).
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 240_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(360)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(240_000, $baseLedger->monthly_amount_eur_cents);
    }

    public function test_commission_base_window_is_the_first_of_the_next_month_only(): void
    {
        // Una tantum (2026-07-22): la riga di base e' valida SOLO il 1° del
        // mese successivo — il run mensile la cattura una volta e mai piu'.
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 120_000);

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $expected = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->assertSame($expected, \Illuminate\Support\Carbon::parse($baseLedger->valid_from)->toDateString());
        $this->assertSame($expected, \Illuminate\Support\Carbon::parse($baseLedger->valid_until)->toDateString());
    }

    public function test_admin_configured_rules_override_the_seeded_ones(): void
    {
        // L'admin puo' cambiare punti e durata di un taglio (o aggiungerne):
        // il servizio legge SEMPRE la tabella, non costanti nel codice.
        MlmPointRule::where('event_type', 'deposit')->where('deposit_amount_eur_cents', 12_000)
            ->update(['points' => 3.5, 'duration_days' => 45]);

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(3.5, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(45)));
        $this->assertSame('3,5', mlm_points_format($agent->mlmActivePoints()));
    }

    public function test_points_from_multiple_clients_add_up(): void
    {
        // Tre ricariche da tagli diversi: 2 + 2 + 2 = 6 punti attivi.
        $agent = $this->makeAgent();

        $this->service->awardDepositPoints($this->makeClient($agent), 12_000);
        $this->service->awardDepositPoints($this->makeClient($agent), 60_000);
        $this->service->awardDepositPoints($this->makeClient($agent), 120_000);

        $this->assertSame(6, $agent->mlmActivePoints());
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

    public function test_deposit_below_the_minimum_tier_awards_no_points_and_no_base(): void
    {
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 5_000); // 50 EUR, sotto il taglio minimo (120 EUR)

        $this->assertSame(0, MlmPointLedgerEntry::where('agent_user_id', $agent->id)->count());
        $this->assertSame(0, MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->count());
        $this->assertSame(0, $agent->mlmActivePoints());
    }

    public function test_deleting_the_registration_rule_disables_registration_points(): void
    {
        // "Se l'admin elimina una riga, quell'evento smette di generare
        // punti" — vale anche per l'apertura conto.
        MlmPointRule::where('event_type', 'registration')->delete();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardRegistrationPoints($client);

        $this->assertSame(0, MlmPointLedgerEntry::count());
    }

    public function test_points_validity_override_forces_a_short_expiry(): void
    {
        SystemSetting::mlmSettings()->forceFill(['mlm_points_validity_override_minutes' => 60])->save();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        // Anche il taglio piu' lungo (1.200 EUR -> 360 giorni) deve
        // rispettare l'override di 1 ora.
        $this->service->awardDepositPoints($client, 120_000);

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
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(360)));
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
