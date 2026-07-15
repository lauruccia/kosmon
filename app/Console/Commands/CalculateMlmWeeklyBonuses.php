<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MlmAwardService;
use App\Services\MlmBonusService;
use Illuminate\Console\Command;

/**
 * Job settimanale (ogni mercoledi', vedi routes/console.php e
 * MLM_PROPOSAL.md §9/§11 punto 5): calcola e accredita TUTTI i bonus EUR
 * "settimanali" MLM in un'unica passata:
 *
 *  1. Cascata bonus di struttura: elabora gli eventi BasiQ rilevati (e non
 *     ancora processati) dal job notturno mlm:recalculate-points, calcola la
 *     catena upline con sottrazione telescopica e crea i payout in
 *     mlm_bonus_payouts (MlmBonusService::processPendingEvents).
 *  2. Bonus Diretti KNM: rivaluta le soglie di punti attivi (4/6/12) per ogni
 *     agente e paga quelle raggiunte non ancora premiate
 *     (MlmAwardService::grantDirectPointBonuses).
 *  3. Extra Bonus KNM: eroga i premi di promozione grado accodati durante la
 *     settimana da MlmRankEngine::syncRank()
 *     (MlmAwardService::processPendingRankAwards).
 *
 * Il RILEVAMENTO (stato BasiQ, avanzamento/retrocessione di qualifica) resta
 * giornaliero in mlm:recalculate-points, perche' e' lo stato visibile nel
 * portale — solo il CALCOLO DEGLI IMPORTI e la creazione dei
 * mlm_bonus_payouts vengono fatti qui, una volta a settimana. Questo era gia'
 * il disegno originale della proposta (job "CalculateWeeklyMlmBonuses"
 * distinto dal job giornaliero punti/qualifiche), mai implementato come
 * comando separato fino al 2026-07-15: prima tutto veniva calcolato subito,
 * dentro il job notturno, nell'istante in cui scattava la condizione.
 *
 * Idempotente: rieseguirlo (o farlo girare in un momento diverso dal
 * mercoledi', es. per un ricalcolo manuale) non duplica nulla, perche' ogni
 * sotto-passata si appoggia sull'idempotenza gia' esistente
 * (MlmBonusEvent.status, MlmBonusPayout.idempotency_key,
 * MlmPendingRankAward.processed_at).
 */
class CalculateMlmWeeklyBonuses extends Command
{
    protected $signature = 'mlm:calculate-weekly-bonuses';

    protected $description = 'Calcola e accredita i bonus MLM settimanali (cascata di struttura, bonus diretti, extra bonus grado)';

    public function __construct(
        private readonly MlmBonusService $bonusService,
        private readonly MlmAwardService $awardService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $processedEvents = $this->bonusService->processPendingEvents();
        $this->info("Cascata bonus di struttura: {$processedEvents} eventi BasiQ elaborati.");

        $directBonuses = 0;
        User::query()->where('mlm_role', 'agente')->each(function (User $agent) use (&$directBonuses) {
            $directBonuses += $this->awardService->grantDirectPointBonuses($agent);
        });
        $this->info("Bonus Diretti KNM: {$directBonuses} nuove soglie premiate.");

        $rankAwards = $this->awardService->processPendingRankAwards();
        $this->info("Extra Bonus KNM: {$rankAwards} premi di promozione erogati.");

        return self::SUCCESS;
    }
}
