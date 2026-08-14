<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmBonusPayout;
use App\Models\MlmPendingRankAward;
use App\Models\SystemSetting;
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
 *    DISATTIVATI DI DEFAULT dal 2026-08-14 (richiesta di Laura): l'erogazione
 *    passa dall'interruttore admin SystemSetting::mlmDirectBonusesEnabled()
 *    (/admin/mlm-impostazioni). Il codice resta, cosi' si possono riaccendere
 *    senza rimetterci mano. Gli EXTRA BONUS qui sotto NON sono toccati.
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
 *
 * CALCOLO SPOSTATO AL JOB SETTIMANALE (2026-07-15, vedi MLM_PROPOSAL.md §9):
 * grantDirectPointBonuses() viene ora chiamato da `mlm:calculate-weekly-bonuses`
 * (ogni mercoledi') per tutti gli agenti, non piu' ogni notte da
 * `mlm:recalculate-points`. L'Extra Bonus non viene piu' erogato subito al
 * momento della promozione (rilevata comunque ogni notte da
 * MlmRankEngine::syncRank()): la promozione viene solo ACCODATA
 * (queueRankAward, tabella mlm_pending_rank_awards) e l'erogazione vera e
 * propria (grantRankAward) avviene anch'essa nel job settimanale
 * (processPendingRankAwards).
 *
 * BONUS DIRETTI KNM: REALI + OMAGGIO (confermato di nuovo da Laura il
 * 2026-07-30, stessa regola del 2026-07-14, vedi
 * [[mlm_punti_agenti_omaggio]] in memoria di progetto): un punto omaggio
 * genera i Bonus Diretti esattamente come un punto reale —
 * grantDirectPointBonuses() resta quindi su User::mlmActivePoints() (reali
 * + omaggio, INVARIATO). Il confine "solo dati reali" del 2026-07-30 vale
 * invece per il rilevamento BasiQ (RecalculateMlmPoints, che innesca la
 * CASCATA bonus di struttura sull'upline): quello si', da oggi usa
 * User::mlmRealActivePoints() (solo ledger) — vedi RecalculateMlmPoints per
 * il motivo della distinzione fra i due tipi di bonus.
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
     * Verifica le soglie dei Bonus Diretti sui punti ATTIVI dell'agente
     * (reali + omaggio, vedi docblock di classe) e paga le soglie raggiunte
     * non ancora premiate. Restituisce il numero di nuovi bonus creati.
     *
     * DISATTIVABILE DA ADMIN (2026-08-14): se l'interruttore
     * system_settings.mlm_direct_bonuses_enabled e' spento (default, vedi
     * SystemSetting::mlmDirectBonusesEnabled()) questo metodo e' un no-op e
     * restituisce 0 — nessun payout, nessun accredito nel cassetto kmoney.
     * L'interruttore vale SOLO per i Bonus Diretti: bonus di struttura
     * (MlmBonusService) ed Extra Bonus di grado (grantRankAward) continuano
     * a funzionare come prima.
     */
    public function grantDirectPointBonuses(User $agent): int
    {
        if (! SystemSetting::mlmSettings()->mlmDirectBonusesEnabled()) {
            return 0;
        }

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

    /**
     * Accoda la promozione dell'agente al grado indicato per l'erogazione
     * dell'Extra Bonus, senza creare subito il payout: la vera erogazione
     * (grantRankAward, con la sua idempotenza) avviene nel job settimanale
     * (processPendingRankAwards). No-op per i gradi senza premio o per un
     * grado gia' premiato in passato (idempotency_key gia' presente in
     * mlm_bonus_payouts, es. dati storici pre-2026-07-15 o una chiamata
     * diretta a grantRankAward) — evita di rimettere in coda un premio che
     * verrebbe comunque rifiutato da grantRankAward, ma soprattutto rende
     * l'assenza di riga in coda un segnale affidabile di "mai stato premiato
     * O gia' incassato", utile lato UI/audit.
     *
     * Il vincolo UNIQUE (user_id, rank) su mlm_pending_rank_awards fa inoltre
     * si' che una promozione allo stesso grado NON venga mai rimessa in coda
     * due volte finche' la riga precedente non e' stata processata — coerente
     * con la regola "mai due volte per lo stesso grado, anche dopo
     * retrocessione e nuova promozione".
     */
    public function queueRankAward(User $agent, string $newRank): void
    {
        if (! array_key_exists($newRank, self::RANK_AWARDS_EUR_CENTS)) {
            return;
        }

        $idempotencyKey = "mlm_rank_award_{$agent->id}_{$newRank}";
        if (MlmBonusPayout::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        MlmPendingRankAward::firstOrCreate(
            ['user_id' => $agent->id, 'rank' => $newRank],
            ['detected_at' => now()]
        );
    }

    /**
     * Elabora tutte le promozioni accodate e non ancora processate: eroga
     * l'Extra Bonus (grantRankAward, idempotente) e segna la riga come
     * processata. Chiamato dal job settimanale
     * (`mlm:calculate-weekly-bonuses`). Restituisce il numero di premi
     * effettivamente creati (puo' essere minore del numero di righe accodate
     * se grantRankAward risultasse gia' idempotente per altra via).
     */
    public function processPendingRankAwards(): int
    {
        $granted = 0;

        MlmPendingRankAward::whereNull('processed_at')->with('user')->get()
            ->each(function (MlmPendingRankAward $pending) use (&$granted) {
                if ($pending->user && $this->grantRankAward($pending->user, $pending->rank)) {
                    $granted++;
                }

                $pending->forceFill(['processed_at' => now()])->save();
            });

        return $granted;
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
        $bonusPayout = MlmBonusPayout::create([
            'mlm_bonus_event_id' => null,
            'beneficiary_user_id' => $agent->id,
            'rank_at_time' => $rankAtTime,
            'kind' => $kind,
            'amount_eur_cents' => $amountEurCents,
            'week_ending' => $this->nextWednesday(now())->toDateString(),
            'status' => 'pending',
            'idempotency_key' => $idempotencyKey,
        ]);

        // Cassetto kmoney (2026-07-30): accredito subito in KY, vedi MlmWalletService.
        app(MlmWalletService::class)->creditFromBonusPayout($bonusPayout);

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
