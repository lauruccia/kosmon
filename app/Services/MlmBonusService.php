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
 * Quando un agente diventa BasiQ, si genera un bonus pari all'importo della
 * qualifica piu' alta presente nella catena upline (dal BasiQ fino alla
 * radice). L'importo si distribuisce fra TUTTE le qualifiche bonus-eligibili
 * (key..manager) effettivamente presenti in catena, dal basso verso l'alto,
 * a sottrazione telescopica:
 *
 *   payout(rank) = importo(rank) - importo(rank presente immediatamente inferiore)
 *
 * Se una qualifica non e' presente in catena viene semplicemente saltata
 * (chi sta sopra assorbe la differenza). La somma dei payout e' sempre pari
 * all'importo della qualifica piu' alta presente — verificato in test.
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

            // Un solo beneficiario per qualifica: il piu' vicino al BasiQ fra
            // chi ha quella qualifica (evita doppio conteggio se la stessa
            // qualifica compare piu' volte nella stessa catena).
            $presentByRank = [];
            foreach ($upline as $ancestor) {
                if (! array_key_exists($ancestor->mlm_rank, self::BONUS_AMOUNTS_EUR_CENTS)) {
                    continue;
                }
                if (! isset($presentByRank[$ancestor->mlm_rank])) {
                    $presentByRank[$ancestor->mlm_rank] = $ancestor;
                }
            }

            if (isset($presentByRank['key']) && ! $this->keyIsBonusEligible($presentByRank['key'], $event->triggered_at)) {
                unset($presentByRank['key']);
            }

            if (empty($presentByRank)) {
                $event->forceFill(['status' => 'processed', 'processed_at' => now(), 'upline_chain_snapshot' => []])->save();

                return;
            }

            uksort($presentByRank, fn (string $a, string $b) => $this->rankOrderIndex($a) <=> $this->rankOrderIndex($b));

            $weekEnding = $this->nextWednesday(now());
            $previousAmount = 0;
            $snapshot = [];

            foreach ($presentByRank as $rank => $beneficiary) {
                $tierAmount = self::BONUS_AMOUNTS_EUR_CENTS[$rank];
                $payoutAmount = $tierAmount - $previousAmount;
                $previousAmount = $tierAmount;

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

    private function rankOrderIndex(string $rank): int
    {
        $index = array_search($rank, array_keys(self::BONUS_AMOUNTS_EUR_CENTS), true);

        return $index === false ? 0 : $index;
    }
}
