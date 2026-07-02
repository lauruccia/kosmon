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
     * Processa l'evento BasiQ per l'agente indicato: crea/recupera
     * MlmBonusEvent (idempotente per agente) e, se non gia' processato,
     * calcola e registra i payout in mlm_bonus_payouts.
     */
    public function processBasiqEvent(User $basiqAgent): MlmBonusEvent
    {
        return DB::transaction(function () use ($basiqAgent) {
            $event = MlmBonusEvent::where('basiq_user_id', $basiqAgent->id)->first();

            if (! $event) {
                $event = MlmBonusEvent::create([
                    'basiq_user_id' => $basiqAgent->id,
                    'triggered_at' => now(),
                    'status' => 'pending',
                ]);
            }

            if ($event->status !== 'pending') {
                return $event;
            }

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

                return $event;
            }

            uksort($presentByRank, fn (string $a, string $b) => $this->rankOrderIndex($a) <=> $this->rankOrderIndex($b));

            $weekEnding = $this->nextWednesday($event->triggered_at);
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

            return $event;
        });
    }

    /**
     * Un agente Key e' eleggibile al bonus solo dal 3° evento BasiQ (incluso
     * questo) nella sua downline. Conta gli eventi BasiQ gia' processati o in
     * corso di elaborazione, fino a $upToTime incluso.
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
