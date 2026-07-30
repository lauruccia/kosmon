<?php

namespace Tests\Feature;

use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\User;
use App\Services\MlmBonusService;
use App\Services\MlmTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Copre la cascata bonus di struttura con la regola "per POSIZIONE"
 * (2026-07-20, testo letterale della slide: ogni upline sottrae il bonus
 * della maggiore qualifica presente fra il BasiQ e se stesso) e
 * l'idempotenza dell'evento. Nel caso normale di gradi crescenti verso
 * l'alto la regola per posizione coincide con la vecchia telescopica.
 * Copre anche (2026-07-29) lo snapshot del rank di ogni upline al momento
 * del rilevamento BasiQ, usato dal calcolo settimanale al posto del rank
 * corrente al momento dell'elaborazione — con fallback per gli eventi
 * pre-esistenti senza snapshot. Dal 2026-07-30 il Key e' bonus-eligibile
 * fin dal primo evento BasiQ nella sua downline (rimossa la precedente
 * soglia dei 3 eventi, mai richiesta da Laura — vedi MlmBonusService).
 *
 * Vedi app/Services/MlmBonusService.php e MLM_PROPOSAL.md §6.
 */
class MlmBonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private MlmBonusService $service;
    private MlmTreeService $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MlmTreeService();
        $this->service = new MlmBonusService($this->tree);
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

    public function test_cascade_telescopes_amounts_across_the_full_chain(): void
    {
        $manager = $this->makeAgent('manager');
        $supervisor = $this->makeAgent('supervisor');
        $top = $this->makeAgent('top');
        $senior = $this->makeAgent('senior');
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($manager, null);
        $this->tree->attachAgent($supervisor, $manager);
        $this->tree->attachAgent($top, $supervisor);
        $this->tree->attachAgent($senior, $top);
        $this->tree->attachAgent($key, $senior);
        $this->tree->attachAgent($basiq, $key);

        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        $this->assertSame(6_000, (int) $payouts[$key->id]->amount_eur_cents);
        $this->assertSame(5_000, (int) $payouts[$senior->id]->amount_eur_cents); // 11.000 - 6.000
        $this->assertSame(4_000, (int) $payouts[$top->id]->amount_eur_cents); // 15.000 - 11.000
        $this->assertSame(3_000, (int) $payouts[$supervisor->id]->amount_eur_cents); // 18.000 - 15.000
        $this->assertSame(2_000, (int) $payouts[$manager->id]->amount_eur_cents); // 20.000 - 18.000

        // La somma dei payout deve essere pari all'importo della qualifica piu' alta presente.
        $this->assertSame(20_000, (int) $payouts->sum('amount_eur_cents'));
    }

    public function test_key_is_bonus_eligible_from_the_very_first_basiq_event_in_its_downline(): void
    {
        // Regola dei "3 eventi" rimossa il 2026-07-30: nessun evento
        // pregresso nella downline del Key, eppure incassa subito.
        $senior = $this->makeAgent('senior');
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($key, $senior);
        $this->tree->attachAgent($basiq, $key);

        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        // Key: primo bonus-eligibile della catena, incassa il pieno.
        $this->assertSame(6_000, (int) $payouts[$key->id]->amount_eur_cents);
        // Senior sopra di lui: 110 - 60 (regola per posizione, invariata).
        $this->assertSame(5_000, (int) $payouts[$senior->id]->amount_eur_cents);
    }

    public function test_process_basiq_event_is_idempotent(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        $event1 = $this->service->processBasiqEvent($basiq);
        $countAfterFirst = MlmBonusPayout::count();

        $event2 = $this->service->processBasiqEvent($basiq);
        $countAfterSecond = MlmBonusPayout::count();

        $this->assertSame($event1->id, $event2->id);
        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }

    public function test_no_bonus_eligible_ancestor_marks_event_processed_without_payouts(): void
    {
        $plainRoot = $this->makeAgent('start');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($plainRoot, null);
        $this->tree->attachAgent($basiq, $plainRoot);

        $event = $this->service->processBasiqEvent($basiq);

        $this->assertSame('processed', $event->status);
        $this->assertSame(0, MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->count());
    }

    /**
     * Copre il flusso di produzione reale dal 2026-07-15: il job notturno
     * chiama solo recordBasiqEvent() (rilevamento), il job settimanale del
     * mercoledi' chiama processPendingEvents() (calcolo/accredito). I due
     * passi devono restare separabili: recordBasiqEvent da solo non deve MAI
     * creare payout.
     */
    public function test_record_basiq_event_alone_does_not_create_any_payout(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        $event = $this->service->recordBasiqEvent($basiq);

        $this->assertSame('pending', $event->status);
        $this->assertSame(0, MlmBonusPayout::count());
    }

    public function test_process_pending_events_calculates_payouts_for_events_recorded_earlier(): void
    {
        $senior = $this->makeAgent('senior');
        $basiqA = $this->makeAgent('basic');
        $basiqB = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiqA, $senior);
        $this->tree->attachAgent($basiqB, $senior);

        // Rilevamento notturno di due eventi in giorni diversi della stessa settimana.
        $this->service->recordBasiqEvent($basiqA);
        $this->service->recordBasiqEvent($basiqB);
        $this->assertSame(0, MlmBonusPayout::count(), 'Nessun payout prima dell\'elaborazione settimanale.');

        // Elaborazione settimanale (mercoledi'): entrambi gli eventi vengono processati.
        $processedCount = $this->service->processPendingEvents();

        $this->assertSame(2, $processedCount);
        $this->assertSame(2, MlmBonusPayout::where('beneficiary_user_id', $senior->id)->count());

        // Idempotente: rieseguire non elabora nulla di nuovo.
        $this->assertSame(0, $this->service->processPendingEvents());
        $this->assertSame(2, MlmBonusPayout::where('beneficiary_user_id', $senior->id)->count());
    }

    public function test_positional_rule_a_lower_rank_above_a_higher_rank_earns_nothing(): void
    {
        // Slide letterale (2026-07-20): "sottraendo al bonus relativo alla
        // propria qualifica il bonus relativo alla maggiore qualifica
        // presente fra chi diventa BasiQ e se stesso". Catena (dal basso):
        // BasiQ -> Senior -> Key -> Top. Il Senior (primo sopra il BasiQ)
        // incassa 110 pieni; il Key sopra di lui avrebbe 60 - 110 < 0 => 0;
        // il Top incassa 150 - 110 = 40. Totale = 150 (grado piu' alto).
        $top = $this->makeAgent('top');
        $key = $this->makeAgent('key');
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($top, null);
        $this->tree->attachAgent($key, $top);
        $this->tree->attachAgent($senior, $key);
        $this->tree->attachAgent($basiq, $senior);

        // La regola per posizione esclude comunque il Key perche' sotto di
        // lui c'e' gia' un Senior (110 > 60) — indipendente dall'eleggibilita'.
        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        $this->assertSame(11_000, (int) $payouts[$senior->id]->amount_eur_cents);
        $this->assertArrayNotHasKey($key->id, $payouts->toArray(), 'Il Key sopra un Senior non deve incassare nulla (60 - 110 < 0).');
        $this->assertSame(4_000, (int) $payouts[$top->id]->amount_eur_cents); // 150 - 110
        $this->assertSame(15_000, (int) $payouts->sum('amount_eur_cents'), 'La somma resta il bonus del grado piu\' alto presente.');
    }

    public function test_positional_rule_pays_only_the_first_occurrence_of_a_repeated_rank(): void
    {
        // Due Senior in catena: il piu' vicino al BasiQ incassa 110, il
        // secondo sottrae il Senior sotto di se' (110 - 110 = 0).
        $seniorFar = $this->makeAgent('senior');
        $seniorNear = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');

        $this->tree->attachAgent($seniorFar, null);
        $this->tree->attachAgent($seniorNear, $seniorFar);
        $this->tree->attachAgent($basiq, $seniorNear);

        $event = $this->service->processBasiqEvent($basiq);

        $payouts = MlmBonusPayout::where('mlm_bonus_event_id', $event->id)->get()->keyBy('beneficiary_user_id');

        $this->assertSame(11_000, (int) $payouts[$seniorNear->id]->amount_eur_cents);
        $this->assertArrayNotHasKey($seniorFar->id, $payouts->toArray());
    }

    /**
     * Decisione di Laura (2026-07-29): la cascata deve usare "le qualifiche
     * del momento" in cui BasiQ e' scattato, non quelle correnti al momento
     * in cui il job settimanale elabora l'evento. Qui un upline viene
     * promosso da Key a Senior TRA il rilevamento (notte) e l'elaborazione
     * (mercoledi'): il payout deve comunque riflettere il grado Key
     * fotografato al rilevamento, non il Senior attuale.
     */
    public function test_cascade_uses_the_ancestor_rank_snapshotted_at_detection_not_the_rank_at_processing_time(): void
    {
        $key = $this->makeAgent('key');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($key, null);
        $this->tree->attachAgent($basiq, $key);

        // Rilevamento notturno: fotografa il Key come "key".
        $this->service->recordBasiqEvent($basiq);

        // Nel frattempo (prima del mercoledi'), il Key viene promosso a Senior.
        $key->forceFill(['mlm_rank' => 'senior'])->save();

        // Elaborazione settimanale.
        $this->service->processPendingEvents();

        $payout = MlmBonusPayout::where('beneficiary_user_id', $key->id)->first();
        $this->assertNotNull($payout);
        $this->assertSame('key', $payout->rank_at_time, 'Deve usare il grado fotografato al rilevamento, non quello attuale (senior).');
        $this->assertSame(6_000, (int) $payout->amount_eur_cents, 'Importo del grado Key (60), non quello di Senior (110).');
    }

    /**
     * Retrocompatibilita': un evento 'pending' creato PRIMA di questa
     * modifica (colonna upline_ranks_at_trigger assente/null) deve
     * continuare a funzionare, ricadendo sul rank corrente dell'upline al
     * momento dell'elaborazione (comportamento pre-29/07).
     */
    public function test_processing_falls_back_to_current_rank_when_no_snapshot_was_recorded(): void
    {
        $senior = $this->makeAgent('senior');
        $basiq = $this->makeAgent('basic');
        $this->tree->attachAgent($senior, null);
        $this->tree->attachAgent($basiq, $senior);

        // Evento creato senza upline_ranks_at_trigger, come un evento 'pending'
        // gia' esistente in produzione prima della migration.
        MlmBonusEvent::create([
            'basiq_user_id' => $basiq->id,
            'triggered_at' => now(),
            'status' => 'pending',
        ]);

        $this->service->processPendingEvents();

        $payout = MlmBonusPayout::where('beneficiary_user_id', $senior->id)->first();
        $this->assertNotNull($payout);
        $this->assertSame(11_000, (int) $payout->amount_eur_cents);
    }
}
