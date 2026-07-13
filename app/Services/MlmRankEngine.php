<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmRankHistory;
use App\Models\User;
use App\Notifications\MlmRankDemotedNotification;
use Illuminate\Support\Collection;

/**
 * Motore di valutazione delle qualifiche agente. Vedi MLM_PROPOSAL.md §4.2.
 *
 * Requisiti allineati al testo letterale della slide "Qualifiche" KNM
 * (confermata da Laura il 2026-07-13, screenshot caricato in chat — fa fede
 * su qualunque altra fonte, incluso il foglio mlm_piano.xlsx che su
 * SuperVisor/Manager usava una notazione a punti PS/PT/PSPV/PM ambigua e
 * meno letterale):
 *
 *   Basic = 12 pt
 *   Key   = 24 pt + 2 Basic al 1° liv.
 *   Senior = 48 pt + 3 Basic al 1° liv. + 2 Key su 2 colonne diverse
 *   Top    = 48 pt + 4 Basic al 1° liv. + 3 colonne da 300 punti attivi
 *   SuperVisor = 48 pt + 5 Basic al 1° liv. + 2 Senior e 2 Top su 4 colonne diverse
 *   Manager    = 48 pt + 6 Basic al 1° liv. + 3 SuperVisor su 3 colonne diverse
 *
 * Il numero di Basic al 1° livello cresce in modo monotono con il grado
 * (2/3/4/5/6). Per SuperVisor, "2 Senior e 2 Top" e' implementato come
 * branchesWithTop>=2 (le 2 colonne Top) E branchesWithSenior>=4 (il totale
 * delle colonne con almeno un Senior, che include gia' le 2 Top dato che
 * Top > Senior nell'ordine dei gradi) — equivalente esatto del testo della
 * slide, vedi countBranchesWithMinRank(). Ogni qualifica e' comunque un
 * requisito indipendente, NON una progressione stretta: il motore valuta
 * TUTTE le qualifiche e assegna la piu' alta soddisfatta (es. un agente puo'
 * soddisfare "manager" senza soddisfare "top" o "supervisor", se non ha le
 * colonne da 300 punti ma ha 3 SuperVisor in downline).
 *
 * RETROCESSIONE (confermata da Laura il 2026-07-13): i punti hanno una
 * finestra di validita' (mlm_point_ledger.valid_from/valid_until) e quando
 * scadono i requisiti possono venire meno. syncRank() allinea quindi il
 * grado in ENTRAMBE le direzioni — promuove e retrocede — fino a "start",
 * senza grado minimo garantito. Il flag storico BasiQ (mlm_basiq_at) non
 * viene mai toccato. Nessun ricalcolo retroattivo di bonus/commissioni gia'
 * generati: la retrocessione vale solo dalle valutazioni successive.
 */
class MlmRankEngine
{
    /** Ordine crescente delle qualifiche valutate da questo motore (esclude "start"). */
    private const ORDER = ['basic', 'key', 'senior', 'top', 'supervisor', 'manager'];

    public function __construct(
        private readonly MlmTreeService $tree,
        private readonly MlmAwardService $awards,
    ) {}

    /**
     * Valuta l'agente e restituisce un array diagnostico completo:
     * - 'points' => punti attivi
     * - 'level1_basic_count' => n. figli diretti con rank >= basic
     * - 'branches_300pt' => n. colonne con >= 300 punti attivi
     * - 'branches_with_key' => n. colonne con almeno un agente >= key
     * - 'branches_with_senior' => n. colonne con almeno un agente >= senior
     * - 'branches_with_top' => n. colonne con almeno un agente >= top
     * - 'branches_with_supervisor' => n. colonne con almeno un agente >= supervisor
     * - 'satisfied' => ['basic' => bool, 'key' => bool, ...]
     * - 'eligible_rank' => qualifica piu' alta soddisfatta (stringa)
     */
    public function evaluate(User $agent): array
    {
        $branches = $this->tree->branchSummaries($agent);

        $points = $agent->mlmActivePoints();
        $level1BasicCount = $branches->filter(
            fn (array $b) => $this->rankLevel($b['branch_root']->mlm_rank) >= $this->rankLevel('basic')
        )->count();

        $branches300pt = $branches->filter(fn (array $b) => $b['active_points'] >= 300)->count();
        $branchesWithKey = $this->countBranchesWithMinRank($branches, 'key');
        $branchesWithSenior = $this->countBranchesWithMinRank($branches, 'senior');
        $branchesWithTop = $this->countBranchesWithMinRank($branches, 'top');
        $branchesWithSupervisor = $this->countBranchesWithMinRank($branches, 'supervisor');

        $satisfied = [
            'basic' => $points >= 12,
            'key' => $points >= 24 && $level1BasicCount >= 2,
            'senior' => $points >= 48 && $level1BasicCount >= 3 && $branchesWithKey >= 2,
            'top' => $points >= 48 && $level1BasicCount >= 4 && $branches300pt >= 3,
            // "2 Senior e 2 Top su 4 colonne diverse": un ramo con un Top soddisfa
            // automaticamente anche la soglia Senior (Top > Senior), quindi basta
            // richiedere almeno 2 colonne >= top E almeno 4 colonne >= senior in
            // totale (le 2 "top" sono gia' incluse nel conteggio "senior").
            'supervisor' => $points >= 48 && $level1BasicCount >= 5 && $branchesWithTop >= 2 && $branchesWithSenior >= 4,
            'manager' => $points >= 48 && $level1BasicCount >= 6 && $branchesWithSupervisor >= 3,
        ];

        $eligibleRank = 'start';
        foreach (self::ORDER as $rank) {
            if ($satisfied[$rank]) {
                $eligibleRank = $rank;
            }
        }

        return [
            'points' => $points,
            'level1_basic_count' => $level1BasicCount,
            'branches_300pt' => $branches300pt,
            'branches_with_key' => $branchesWithKey,
            'branches_with_senior' => $branchesWithSenior,
            'branches_with_top' => $branchesWithTop,
            'branches_with_supervisor' => $branchesWithSupervisor,
            'satisfied' => $satisfied,
            'eligible_rank' => $eligibleRank,
        ];
    }

    /**
     * Allinea il grado dell'agente alla qualifica piu' alta soddisfatta,
     * in ENTRAMBE le direzioni: promuove se superiore all'attuale,
     * RETROCEDE se inferiore (es. punti scaduti nel ledger — confermato
     * da Laura il 2026-07-13, senza grado minimo garantito). Registra lo
     * storico e l'audit log in entrambi i casi.
     *
     * Restituisce 'promoted', 'demoted' oppure null se il grado era gia'
     * corretto.
     */
    public function syncRank(User $agent): ?string
    {
        $evaluation = $this->evaluate($agent);
        $eligibleRank = $evaluation['eligible_rank'];

        $eligibleLevel = $this->rankLevel($eligibleRank);
        $currentLevel = $this->rankLevel($agent->mlm_rank);

        if ($eligibleLevel === $currentLevel) {
            return null;
        }

        $direction = $eligibleLevel > $currentLevel ? 'promoted' : 'demoted';
        $previousRank = $agent->mlm_rank;

        $agent->forceFill([
            'mlm_rank' => $eligibleRank,
            'mlm_rank_updated_at' => now(),
        ])->save();

        MlmRankHistory::create([
            'agent_user_id' => $agent->id,
            'rank' => $eligibleRank,
            'achieved_at' => now(),
            'evaluation_snapshot' => $evaluation,
        ]);

        AuditLog::create([
            'actor_user_id' => null,
            'event' => $direction === 'promoted' ? 'mlm.rank_promoted' : 'mlm.rank_demoted',
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
            'context' => [
                'previous_rank' => $previousRank,
                'new_rank' => $eligibleRank,
                'evaluation' => $evaluation,
            ],
        ]);

        if ($direction === 'promoted') {
            // Extra Bonus una tantum alla prima promozione a senior+ (2026-07-13).
            $this->awards->grantRankAward($agent, $eligibleRank);
        } else {
            // La retrocessione viene comunicata all'agente (email + in-app).
            $agent->notify(new MlmRankDemotedNotification($previousRank, $eligibleRank));
        }

        return $direction;
    }

    /**
     * Checklist dei requisiti per la PROSSIMA qualifica rispetto a quella
     * attuale dell'agente (es. se e' "key", restituisce i requisiti di
     * "senior"). Restituisce null se l'agente e' gia' al grado massimo.
     * Ogni voce: ['label' => string, 'required' => int, 'current' => int, 'met' => bool].
     */
    public function nextRankRequirements(User $agent): ?array
    {
        $currentLevel = $this->rankLevel($agent->mlm_rank);
        if ($currentLevel >= count(self::ORDER)) {
            return null;
        }

        $nextRank = self::ORDER[$currentLevel];
        $evaluation = $this->evaluate($agent);

        $checklists = [
            'basic' => [
                ['label' => 'Punti attivi', 'required' => 12, 'current' => $evaluation['points']],
            ],
            'key' => [
                ['label' => 'Punti attivi', 'required' => 24, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 2, 'current' => $evaluation['level1_basic_count']],
            ],
            'senior' => [
                ['label' => 'Punti attivi', 'required' => 48, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 3, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con almeno un Key+', 'required' => 2, 'current' => $evaluation['branches_with_key']],
            ],
            'top' => [
                ['label' => 'Punti attivi', 'required' => 48, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 4, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con >= 300 punti', 'required' => 3, 'current' => $evaluation['branches_300pt']],
            ],
            'supervisor' => [
                ['label' => 'Punti attivi', 'required' => 48, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 5, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con almeno un Top+', 'required' => 2, 'current' => $evaluation['branches_with_top']],
                ['label' => 'Colonne con almeno un Senior+', 'required' => 4, 'current' => $evaluation['branches_with_senior']],
            ],
            'manager' => [
                ['label' => 'Punti attivi', 'required' => 48, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 6, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con almeno un SuperVisor+', 'required' => 3, 'current' => $evaluation['branches_with_supervisor']],
            ],
        ];

        $items = $checklists[$nextRank] ?? [];
        foreach ($items as &$item) {
            $item['met'] = $item['current'] >= $item['required'];
        }
        unset($item);

        return [
            'rank' => $nextRank,
            'items' => $items,
        ];
    }

    /** Numero di colonne (rami di 1° livello) che contengono almeno un agente di rank >= $minRank. */
    private function countBranchesWithMinRank(Collection $branches, string $minRank): int
    {
        $minLevel = $this->rankLevel($minRank);

        return $branches->filter(function (array $branch) use ($minLevel) {
            foreach ($branch['rank_counts'] as $rank => $count) {
                if ($count > 0 && $this->rankLevel($rank) >= $minLevel) {
                    return true;
                }
            }

            return false;
        })->count();
    }

    private function rankLevel(string $rank): int
    {
        $index = array_search($rank, User::MLM_RANK_ORDER, true);

        return $index === false ? 0 : $index;
    }
}
