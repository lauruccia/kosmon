<?php

namespace Tests\Feature;

use App\Models\KyCard;
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
 * Copre MlmPointsService con le regole "punti per evento" (2026-07-22,
 * decisione di Laura — sostituisce la regola "/12 + frazionari" del
 * 2026-07-20): i punti NON vengono piu' spalmati su 12 mesi ma maturano nel
 * momento dell'evento. L'apertura conto legge la riga 'registration' di
 * mlm_point_rules (seed: 1 punto / 90 giorni); le RICARICHE leggono i punti
 * dalla KY CARD acquistata (osservazione di Laura: i tagli di ricarica reali
 * sono le card di /admin/ky-cards) — mlm_points per mlm_points_duration_days
 * giorni, card per card. Anche la base commissionabile e' una tantum: intero
 * importo, finestra = il solo 1° del mese successivo (vedi
 * MlmCommissionEngineTest per il run che la paga). Copre anche l'override di
 * test (SystemSetting::mlmSettings()->mlm_points_validity_override_minutes,
 * introdotto il 2026-07-13 per permettere scadenze brevi — es. 1 ora —
 * invece di aspettare mesi). Vedi anche MlmRankEngineTest per l'effetto a
 * valle sul calcolo qualifiche.
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

    private function makeCard(int $priceEurCents, float $points, int $durationDays, bool $active = true): KyCard
    {
        return KyCard::create([
            'name'            => 'Card ' . Str::random(6),
            'price_eur_cents' => $priceEurCents,
            'bonus_type'      => 'fixed',
            'ky_base_amount'  => $priceEurCents,
            'bonus_value'     => 0,
            'mlm_points'      => $points,
            'mlm_points_duration_days' => $durationDays,
            'is_active'       => $active,
        ]);
    }

    /** I tre tagli "storici" decisi da Laura il 22/07, come card reali. */
    private function seedDefaultCards(): void
    {
        $this->makeCard(12_000, 2, 30);
        $this->makeCard(60_000, 2, 180);
        $this->makeCard(120_000, 2, 360);
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

    public function test_deposit_awards_the_points_of_the_purchased_card_without_spreading(): void
    {
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        // Card da 1.200 EUR: 2 punti SUBITO, validi 360 giorni. Niente piu'
        // /12: la base commissionabile e' l'intero importo.
        $card = $this->makeCard(120_000, 2, 360);
        $this->service->awardDepositPoints($client, 120_000, null, $card);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->where('client_user_id', $client->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(360)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(120_000, $baseLedger->monthly_amount_eur_cents); // intero importo, una tantum
        // Snapshot del margine KNM ("Prov K") al momento del deposito
        // (default 30%, slide "Esempio compensi" — 2026-07-16).
        $this->assertSame(30, $baseLedger->knm_margin_percent);
    }

    public function test_card_is_resolved_from_the_amount_when_not_passed(): void
    {
        // Simulatore & co.: card non nota, importo esattamente uguale a un
        // taglio -> si usa quella card.
        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(30)));
        $this->assertSame(2, $agent->mlmActivePoints());
    }

    public function test_off_tier_amount_falls_back_to_the_highest_card_below_it(): void
    {
        // 800 EUR non e' un taglio: si usa la card col prezzo piu' alto
        // <= importo (600 EUR -> 2 pt / 180 gg). La base commissionabile
        // resta l'importo REALE della ricarica (800 EUR), non il taglio.
        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 80_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(180)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(80_000, $baseLedger->monthly_amount_eur_cents);
    }

    public function test_amount_above_the_highest_card_uses_the_highest_card(): void
    {
        // 2.400 EUR: nessuna card dedicata (finche' l'admin non la crea in
        // /admin/ky-cards), si usa la card da 1.200 (2 punti / 360 giorni).
        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 240_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(2.0, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(360)));

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(240_000, $baseLedger->monthly_amount_eur_cents);
    }

    public function test_inactive_cards_are_ignored_when_resolving_by_amount(): void
    {
        // La card da 1.200 e' disattivata: un importo da 1.200 EUR ricade
        // sulla card attiva precedente (600 -> 180 giorni).
        $this->makeCard(12_000, 2, 30);
        $this->makeCard(60_000, 2, 180);
        $this->makeCard(120_000, 2, 360, active: false);

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 120_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(180)));
    }

    public function test_commission_base_window_is_the_first_of_the_next_month_only(): void
    {
        // Una tantum (2026-07-22): la riga di base e' valida SOLO il 1° del
        // mese successivo — il run mensile la cattura una volta e mai piu'.
        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 120_000);

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $expected = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->assertSame($expected, \Illuminate\Support\Carbon::parse($baseLedger->valid_from)->toDateString());
        $this->assertSame($expected, \Illuminate\Support\Carbon::parse($baseLedger->valid_until)->toDateString());
    }

    public function test_admin_configured_card_values_are_always_read_live(): void
    {
        // L'admin cambia punti e durata sulla card in /admin/ky-cards: il
        // servizio legge SEMPRE la card, non costanti nel codice.
        $card = $this->makeCard(12_000, 2, 30);
        $card->update(['mlm_points' => 3.5, 'mlm_points_duration_days' => 45]);

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000);

        $entry = MlmPointLedgerEntry::where('agent_user_id', $agent->id)->sole();
        $this->assertEqualsWithDelta(3.5, $entry->points, 0.001);
        $this->assertTrue($entry->valid_until->isSameDay(now()->addDays(45)));
        $this->assertSame('3,5', mlm_points_format($agent->mlmActivePoints()));
    }

    public function test_card_with_zero_points_creates_the_commission_base_but_no_points(): void
    {
        // 0 punti sulla card = la ricarica non genera punti, ma resta una
        // ricarica vera: la base commissionabile nasce comunque (stessa
        // semantica del 22/07 mattina per un taglio con 0 punti).
        $card = $this->makeCard(12_000, 0, 0);
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 12_000, null, $card);

        $this->assertSame(0, MlmPointLedgerEntry::count());
        $this->assertSame(1, MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->count());
    }

    public function test_points_from_multiple_clients_add_up(): void
    {
        // Tre ricariche da card diverse: 2 + 2 + 2 = 6 punti attivi.
        $this->seedDefaultCards();
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

        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);
        $this->service->awardDepositPoints($client, 120_000);

        $baseLedger = MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->sole();
        $this->assertSame(10, $baseLedger->knm_margin_percent);
    }

    public function test_deposit_below_the_cheapest_card_awards_nothing(): void
    {
        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardDepositPoints($client, 5_000); // 50 EUR, sotto la card minima (120 EUR)

        $this->assertSame(0, MlmPointLedgerEntry::where('agent_user_id', $agent->id)->count());
        $this->assertSame(0, MlmCommissionBaseLedgerEntry::where('client_user_id', $client->id)->count());
        $this->assertSame(0, $agent->mlmActivePoints());
    }

    public function test_deleting_the_registration_rule_disables_registration_points(): void
    {
        // "Se l'admin elimina una riga, quell'evento smette di generare
        // punti" — vale per l'apertura conto.
        MlmPointRule::where('event_type', 'registration')->delete();

        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        $this->service->awardRegistrationPoints($client);

        $this->assertSame(0, MlmPointLedgerEntry::count());
    }

    public function test_points_validity_override_forces_a_short_expiry(): void
    {
        SystemSetting::mlmSettings()->forceFill(['mlm_points_validity_override_minutes' => 60])->save();

        $this->seedDefaultCards();
        $agent = $this->makeAgent();
        $client = $this->makeClient($agent);

        // Anche la card piu' lunga (1.200 EUR -> 360 giorni) deve
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

        $this->seedDefaultCards();
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
