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
 * Regola speciale Key: paga solo a partire dal 3° evento BasiQ nella sua
 * downline (i primi 2 sono "consumati" per il requisito di qualifica Key
 * stesso). Se il Key non e' ancora eleggibile, viene trattato come assente
 * nella catena per questo evento (chi sta sopra incassa l'importo pieno,
 * non la differenza).
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
 */
class MlmBonusService
{
    private const BONUS_AMOUNTS_EUR_CENTS = [
        'key' => 6_000,
        'senior' => 11_000,
        'top' => 15_000,
        'supervisor' => 18_000,
        'manager' => 20_000,
    ];

    private const KEY_MIN_BASIQ_EVENTS = 3;

    public function __construct(private readonly MlmTreeService $tree) {}

    /**
     * Registra l'evento BasiQ per l'agente indicato, se non gia' presente
     * (idempotente per agente). NON calcola ne' accredita alcun importo:
     * quello avviene in un secondo momento, in batch settimanale, tramite
     * processPendingEvents(). Chiamato dal job notturno di rilevamento
     * (`mlm:recalculate-points`).
     */
    public function recordBasiqEvent(User $basiqAgent): MlmBonusEvent
    {
        return MlmBonusEvent::firstOrCreate(
            ['basiq_user_id' => $basiqAgent->id],
            ['triggered_at' => now(), 'status' => 'pending']
        );
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

            // Regola "per POSIZIONE" (2026-07-20, testo letterale della
            // slide): si risale la catena dal BasiQ verso la radice e ogni
            // bonus-eligibile percepisce il bonus della propria qualifica
            // MENO il bonus della maggiore qualifica presente fra il BasiQ e
            // se stesso (cioe' gia' incontrata sotto di lui). Un Key sopra un
            // Senior quindi non incassa nulla (60 - 110 < 0), e una qualifica
            // ripetuta paga solo alla prima occorrenza (la seconda sottrae se
            // stessa). Un Key non ancora eleggibile (regola del 3° BasiQ) e'
            // trattato come ASSENTE: niente payout e non abbassa il bonus di
            // chi sta sopra.
            $weekEnding = $this->nextWednesday(now());
            $highestBelowAmount = 0;
            $snapshot = [];

            foreach ($upline as $ancestor) {
                $rank = $ancestor->mlm_rank;
                if (! array_key_exists($rank, self::BONUS_AMOUNTS_EUR_CENTS)) {
                    continue;
                }

                if ($rank === 'key' && ! $this->keyIsBonusEligible($ancestor, $event->triggered_at)) {
                    continue;
                }

                $tierAmount = self::BONUS_AMOUNTS_EUR_CENTS[$rank];
                $payoutAmount = $tierAmount - $highestBelowAmount;
                $highestBelowAmount = max($highestBelowAmount, $tierAmount);

                if ($payoutAmount <= 0) {
                    continue;
                }

                $beneficiary = $ancestor;

                MlmBonusPayout::create([
                    'mlm_bonus_event_id' => $event->id,
                    'beneficiary_user_id' => $beneficiary->id,
                    'rank_at_time' => $rank,
                    'amount_eur_cents' => $payoutAmount,
                    'week_ending' => $weekEnding->toDateString(),
                    'status' => 'pending',
                    'idempotency_key' => "mlm_bonus_{$event->uuid}_{$rank}",
                ]);

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

    /**
     * Un agente Key e' eleggibile al bonus solo dal 3° evento BasiQ (incluso
     * questo) nella sua downline. Conta gli eventi BasiQ RILEVATI (a
     * prescindere dallo stato pending/processed) fino a $upToTime incluso —
     * cosi' l'ordine di elaborazione settimanale in batch non altera
     * l'eleggibilita', che dipende solo da quando l'evento e' stato
     * rilevato, non da quando viene calcolato il payout.
     */
    private function keyIsBonusEligible(User $keyAgent, Carbon $upToTime): bool
    {
        $count = DB::table('mlm_bonus_events')
            ->join('mlm_agent_closure', 'mlm_agent_closure.descendant_id', '=', 'mlm_bonus_events.basiq_user_id')
            ->where('mlm_agent_closure.ancestor_id', $keyAgent->id)
            ->where('mlm_bonus_events.triggered_at', '<=', $upToTime)
            ->count();

        return $count >= self::KEY_MIN_BASIQ_EVENTS;
    }

    private function nextWednesday(Carbon $from): Carbon
    {
        $date = $from->copy()->startOfDay();

        return $date->isWednesday() ? $date : $date->next(Carbon::WEDNESDAY);
    }
}
