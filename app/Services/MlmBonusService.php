<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmBonusEvent;
use App\Models\MlmBonusPayout;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cascata bonus di struttura. Vedi MLM_PROPOSAL.md §6.
 *
 * Quando un agente diventa BasiQ, ogni membro bonus-eligibile (key..manager)
 * della catena upline percepisce — TESTO LETTERALE della slide "BasiQ/Bonus"
 * (regola "per POSIZIONE", decisione di Laura del 2026-07-20 che sostituisce
 * la precedente sottrazione telescopica per ordine di grado):
 *
 *   "i bonus percepiti da ognuno dei livelli superiori vengono calcolati
 *    sottraendo al bonus relativo alla propria qualifica il bonus relativo
 *    alla maggiore qualifica presente fra chi diventa BasiQ e se stesso"
 *
 * Ovvero, risalendo la catena dal BasiQ verso la radice:
 *
 *   payout(ancestor) = max(0, importo(rank ancestor) - max importo fra i
 *                      bonus-eligibili incontrati SOTTO di lui nella catena)
 *
 * Nel caso normale (gradi crescenti verso l'alto) coincide con la vecchia
 * telescopica (es. Key 60, Senior 110-60=50). Se pero' una qualifica piu'
 * ALTA sta piu' vicino al BasiQ di una piu' bassa (es. Senior sotto, Key
 * sopra), chi sta sopra con qualifica minore non incassa nulla: Senior 110,
 * Key max(0, 60-110)=0. La somma dei payout e' sempre pari all'importo della
 * qualifica piu' alta presente in catena — verificato in test.
 *
 * REGOLA RIMOSSA (decisione di Laura, 2026-07-30): esisteva una soglia
 * ESPLICITA di 3 eventi BasiQ nella downline del Key prima che incassasse
 * il bonus struttura. Laura non ricordava di averla mai richiesta;
 * verificato che non era fra i 7 punti che MLM_PROPOSAL.md §7 chiedeva
 * esplicitamente di confermare — era un'inferenza aggiunta durante la
 * stesura della proposta (§6.3), mai confermata. RIMOSSA come soglia
 * esplicita, ma il fenomeno che descriveva (i primi eventi non pagano il
 * Key) resta comunque presente come effetto NATURALE, non piu' come regola
 * a se stante: per diventare Key servono 2 Basic al 1° livello (§4.2), e
 * `mlm:recalculate-points` rileva i nuovi BasiQ (PASSATA 1) PRIMA di
 * rivalutare le qualifiche (PASSATA 2, stessa esecuzione) — quindi lo
 * snapshot upline_ranks_at_trigger di un evento che *rende* l'agente Key
 * lo fotografa ancora col rank precedente (non "key"), e semplicemente non
 * compare in BONUS_AMOUNTS_EUR_CENTS per quell'evento (skip naturale nel
 * loop sotto, nessun codice dedicato). Il Key inizia a incassare dal primo
 * evento successivo alla propria promozione, qualunque esso sia — non da
 * un conteggio fisso.
 *
 * RILEVAMENTO vs CALCOLO (separati dal 2026-07-15, vedi MLM_PROPOSAL.md §9):
 * il job notturno `mlm:recalculate-points` rileva solo il nuovo BasiQ e crea
 * l'evento in stato 'pending' (recordBasiqEvent). Il CALCOLO dell'importo e
 * la scrittura dei payout in `mlm_bonus_payouts` avvengono una volta a
 * settimana, ogni mercoledi', nel job `mlm:calculate-weekly-bonuses`
 * (processPendingEvents) — come previsto dal disegno originale della
 * proposta (`CalculateWeeklyMlmBonuses`), mai implementato come comando
 * separato fino ad ora: prima veniva calcolato subito, la notte stessa in
 * cui l'agente diventava BasiQ.
 *
 * QUALIFICA USATA DALLA CASCATA (decisione di Laura, 2026-07-29): "BasiQ
 * genera comunque il bonus, con le qualifiche del momento [in cui BasiQ
 * scatta]". Anche se il CALCOLO/ACCREDITO resta settimanale (mercoledi'),
 * l'importo di ciascun upline si basa sulla qualifica che quell'upline
 * aveva nell'istante esatto in cui l'evento e' stato rilevato
 * (recordBasiqEvent), NON su quella che ha nel momento in cui il job
 * settimanale elabora materialmente l'evento (che puo' cadere giorni dopo e
 * trovarlo gia' promosso/retrocesso). Per questo recordBasiqEvent()
 * fotografa il rank di ogni upline in `upline_ranks_at_trigger` (colonna
 * JSON), e processEvent() legge da li' invece che da $ancestor->mlm_rank —
 * con fallback al rank corrente per eventuali eventi 'pending' pre-esistenti
 * senza lo snapshot (creati prima di questa modifica).
 */
class MlmBonusService
{
    /** Pubbliche dal 2026-07-21: riusate dal simulatore admin (MlmSimulationService) per spiegare la cascata senza duplicare gli importi. */
    public const BONUS_AMOUNTS_EUR_CENTS = [
        'key' => 6_000,
        'senior' => 11_000,
        'top' => 15_000,
        'supervisor' => 18_000,
        'manager' => 20_000,
    ];

    public function __construct(private readonly MlmTreeService $tree) {}

    /**
     * Registra l'evento BasiQ per l'agente indicato, se non gia' presente
     * (idempotente per agente). NON calcola ne' accredita alcun importo:
     * quello avviene in un secondo momento, in batch settimanale, tramite
     * processPendingEvents(). Chiamato dal job notturno di rilevamento
     * (`mlm:recalculate-points`).
     *
     * Fotografa anche il rank CORRENTE di ogni upline in
     * `upline_ranks_at_trigger`: e' questo scatto, non il rank che l'upline
     * avra' quando il job settimanale elaborera' l'evento, a determinare
     * l'importo del bonus (vedi docblock di classe).
     */
    public function recordBasiqEvent(User $basiqAgent): MlmBonusEvent
    {
        return MlmBonusEvent::firstOrCreate(
            ['basiq_user_id' => $basiqAgent->id],
            [
                'triggered_at' => now(),
                'status' => 'pending',
                'upline_ranks_at_trigger' => $this->snapshotUplineRanks($basiqAgent),
            ]
        );
    }

    /** @return array<int,string> Mappa user_id upline => mlm_rank corrente, per recordBasiqEvent(). */
    private function snapshotUplineRanks(User $basiqAgent): array
    {
        $snapshot = [];

        foreach ($this->tree->orderedUpline($basiqAgent) as $ancestor) {
            $snapshot[$ancestor->id] = $ancestor->mlm_rank;
        }

        return $snapshot;
    }

    /**
     * Processa TUTTI gli eventi BasiQ ancora in stato 'pending': per ciascuno
     * calcola la catena upline e crea i payout telescopici in
     * `mlm_bonus_payouts`. Chiamato dal job settimanale
     * (`mlm:calculate-weekly-bonuses`, ogni mercoledi'). Idempotente: un
     * evento gia' processato viene ignorato. Restituisce il numero di eventi
     * elaborati in questa chiamata.
     */
    public function processPendingEvents(): int
    {
        $processed = 0;

        foreach (MlmBonusEvent::where('status', 'pending')->orderBy('triggered_at')->get() as $event) {
            $this->processEvent($event);
            $processed++;
        }

        return $processed;
    }

    /**
     * @deprecated Mantenuto solo per compatibilita' con eventuali chiamate
     * dirette esistenti: registra ED elabora subito l'evento nella stessa
     * chiamata. Il flusso di produzione ora usa recordBasiqEvent() (notte) +
     * processPendingEvents() (mercoledi') separatamente — vedi il docblock
     * di classe.
     */
    public function processBasiqEvent(User $basiqAgent): MlmBonusEvent
    {
        $event = $this->recordBasiqEvent($basiqAgent);
        $this->processEvent($event);

        return $event->fresh();
    }

    private function processEvent(MlmBonusEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $event = MlmBonusEvent::lockForUpdate()->findOrFail($event->id);

            if ($event->status !== 'pending') {
                return;
            }

            $basiqAgent = $event->basiqUser;

            $upline = $this->tree->orderedUpline($basiqAgent);

            // Rank di ciascun upline al momento del RILEVAMENTO (fotografato
            // da recordBasiqEvent in upline_ranks_at_trigger), non quello
            // corrente al momento di QUESTA elaborazione (che puo' cadere
            // giorni dopo, il mercoledi'). Fallback al rank corrente per gli
            // eventi 'pending' creati prima di questa modifica (senza
            // snapshot in colonna).
            $ranksAtTrigger = $event->upline_ranks_at_trigger ?? [];

            // Regola "per POSIZIONE" (2026-07-20, testo letterale della
            // slide): si risale la catena dal BasiQ verso la radice e ogni
            // bonus-eligibile percepisce il bonus della propria qualifica
            // MENO il bonus della maggiore qualifica presente fra il BasiQ e
            // se stesso (cioe' gia' incontrata sotto di lui). Un Key sopra un
            // Senior quindi non incassa nulla (60 - 110 < 0), e una qualifica
            // ripetuta paga solo alla prima occorrenza (la seconda sottrae se
            // stessa).
            $weekEnding = $this->nextWednesday(now());
            $highestBelowAmount = 0;
            $snapshot = [];

            foreach ($upline as $ancestor) {
                $rank = $ranksAtTrigger[$ancestor->id] ?? $ancestor->mlm_rank;
                if (! array_key_exists($rank, self::BONUS_AMOUNTS_EUR_CENTS)) {
                    continue;
                }

                $tierAmount = self::BONUS_AMOUNTS_EUR_CENTS[$rank];
                $payoutAmount = $tierAmount - $highestBelowAmount;
                $highestBelowAmount = max($highestBelowAmount, $tierAmount);

                if ($payoutAmount <= 0) {
                    continue;
                }

                $beneficiary = $ancestor;

                $bonusPayout = MlmBonusPayout::create([
                    'mlm_bonus_event_id' => $event->id,
                    'beneficiary_user_id' => $beneficiary->id,
                    'rank_at_time' => $rank,
                    'amount_eur_cents' => $payoutAmount,
                    'week_ending' => $weekEnding->toDateString(),
                    'status' => 'pending',
                    'idempotency_key' => "mlm_bonus_{$event->uuid}_{$rank}",
                ]);

                // Cassetto kmoney (2026-07-30): accredito subito in KY, vedi MlmWalletService.
                app(MlmWalletService::class)->creditFromBonusPayout($bonusPayout);

                $snapshot[] = [
                    'rank' => $rank,
                    'beneficiary_user_id' => $beneficiary->id,
                    'amount_eur_cents' => $payoutAmount,
                ];

                AuditLog::create([
                    'actor_user_id' => null,
                    'event' => 'mlm.bonus_payout_created',
                    'auditable_type' => User::class,
                    'auditable_id' => $beneficiary->id,
                    'context' => [
                        'basiq_user_id' => $basiqAgent->id,
                        'rank' => $rank,
                        'amount_eur_cents' => $payoutAmount,
                        'week_ending' => $weekEnding->toDateString(),
                    ],
                ]);
            }

            $event->forceFill([
                'status' => 'processed',
                'processed_at' => now(),
                'upline_chain_snapshot' => $snapshot,
            ])->save();
        });
    }

    private function nextWednesday(Carbon $from): Carbon
    {
        $date = $from->copy()->startOfDay();

        return $date->isWednesday() ? $date : $date->next(Carbon::WEDNESDAY);
    }
}
