<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmBonusPayout;
use App\Models\User;
use Carbon\Carbon;

/**
 * Premi una tantum MLM (introdotti il 2026-07-13, confermati da Laura):
 *
 *  - BONUS DIRETTI KNM (slide "Bonus Diretti"): 200 EUR al raggiungimento di
 *    4 punti personali ATTIVI, 300 EUR a 6 punti, 400 EUR a 12 punti.
 *    Cumulativi (chi arriva a 12 punti incassa nel tempo tutte e tre le
 *    soglie), ma ogni soglia paga UNA SOLA VOLTA nella vita dell'agente —
 *    anche se i punti poi scadono e la soglia viene ri-superata.
 *
 *  - EXTRA BONUS KNM (slide "Extra Bonus"): premio alla PRIMA promozione a
 *    senior (300), top (3.000), supervisor (5.000), manager (20.000 EUR).
 *    Una volta per grado per agente, NON retroattivo (vale solo per le
 *    promozioni registrate dal motore da qui in avanti) e non si ripete in
 *    caso di retrocessione + nuova promozione allo stesso grado.
 *
 * Entrambi confluiscono in mlm_bonus_payouts (accredito il mercoledi',
 * stesso flusso EUR dei bonus di struttura) e quindi nella liquidazione
 * mensile aggregata di MlmPayoutService. L'unicita' e' garantita
 * dall'idempotency_key UNIQUE.
 */
class MlmAwardService
{
    /** Soglie punti attivi => importo (EUR centesimi). */
    public const DIRECT_BONUS_TIERS_EUR_CENTS = [
        4 => 20_000,
        6 => 30_000,
        12 => 40_000,
    ];

    /** Grado raggiunto => premio una tantum (EUR centesimi). */
    public const RANK_AWARDS_EUR_CENTS = [
        'senior' => 30_000,
        'top' => 300_000,
        'supervisor' => 500_000,
        'manager' => 2_000_000,
    ];

    /**
     * Verifica le soglie dei Bonus Diretti sui punti ATTIVI dell'agente e
     * paga le soglie raggiunte non ancora premiate. Restituisce il numero
     * di nuovi bonus creati.
     */
    public function grantDirectPointBonuses(User $agent): int
    {
        $activePoints = $agent->mlmActivePoints();
        $granted = 0;

        foreach (self::DIRECT_BONUS_TIERS_EUR_CENTS as $threshold => $amount) {
            if ($activePoints < $threshold) {
                continue;
            }

            $idempotencyKey = "mlm_direct_bonus_{$agent->id}_pts{$threshold}";
            if (MlmBonusPayout::where('idempotency_key', $idempotencyKey)->exists()) {
                continue;
            }

            $this->createAwardPayout(
                agent: $agent,
                kind: 'diretto',
                rankAtTime: null,
                amountEurCents: $amount,
                idempotencyKey: $idempotencyKey,
                auditEvent: 'mlm.direct_bonus_awarded',
                auditContext: ['threshold_points' => $threshold, 'active_points' => $activePoints],
            );

            $granted++;
        }

        return $granted;
    }

    /**
     * Premia la prima promozione dell'agente al grado indicato (Extra Bonus).
     * No-op per i gradi senza premio o gia' premiati in passato.
     * Restituisce true se il premio e' stato creato.
     */
    public function grantRankAward(User $agent, string $newRank): bool
    {
        $amount = self::RANK_AWARDS_EUR_CENTS[$newRank] ?? null;
        if ($amount === null) {
            return false;
        }

        $idempotencyKey = "mlm_rank_award_{$agent->id}_{$newRank}";
        if (MlmBonusPayout::where('idempotency_key', $idempotencyKey)->exists()) {
            return false;
        }

        $this->createAwardPayout(
            agent: $agent,
            kind: 'extra',
            rankAtTime: $newRank,
            amountEurCents: $amount,
            idempotencyKey: $idempotencyKey,
            auditEvent: 'mlm.rank_award_granted',
            auditContext: ['rank' => $newRank],
        );

        return true;
    }

    private function createAwardPayout(
        User $agent,
        string $kind,
        ?string $rankAtTime,
        int $amountEurCents,
        string $idempotencyKey,
        string $auditEvent,
        array $auditContext,
    ): void {
        MlmBonusPayout::create([
            'mlm_bonus_event_id' => null,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time' => $rankAtTime,
            'kind' => $kind,
            'amount_eur_cents' => $amountEurCents,
            'week_ending' => $this->nextWednesday(now())->toDateString(),
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        AuditLog::create([
            'actor_user_id' => null,
            'event' => $auditEvent,
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
            'context' => $auditContext + [
                'amount_eur_cents' => $amountEurCents,
                'kind' => $kind,
            ],
        ]);
    }

    private function nextWednesday(Carbon $from): Carbon
    {
        $date = $from->copy()->startOfDay();

        return $date->isWednesday() ? $date : $date->next(Carbon::WEDNESDAY);
    }
}
