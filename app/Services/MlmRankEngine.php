<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmRankHistory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Motore di valutazione delle qualifiche agente. Vedi MLM_PROPOSAL.md §4.2.
 *
 * Ogni qualifica e' definita da un requisito indipendente (punti personali +
 * struttura sotto l'agente), NON da una progressione stretta: e' possibile
 * soddisfare i requisiti di una qualifica piu' alta senza soddisfare quelli
 * di una intermedia (es. "Top" richiede solo 3 Basic al 1° livello contro i
 * 4 di "Senior" — dato cosi' nelle fonti). Il motore quindi valuta TUTTE le
 * qualifiche indipendentemente e assegna la piu' alta soddisfatta.
 *
 * Le qualifiche vengono solo PROMOSSE, mai retrocesse automaticamente: se i
 * punti attivi scendono sotto soglia (es. per scadenza nel ledger) l'agente
 * mantiene il grado gia' raggiunto. Questa e' una scelta di design non
 * esplicitata nelle fonti originali — da confermare con Laura se il
 * comportamento desiderato e' diverso.
 */
class MlmRankEngine
{
    /** Ordine crescente delle qualifiche valutate da questo motore (esclude "start"). */
    private const ORDER = ['basic', 'key', 'senior', 'top', 'supervisor', 'manager'];

    public function __construct(private readonly MlmTreeService $tree) {}

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
            'senior' => $points >= 48 && $level1BasicCount >= 4 && $branches300pt >= 3,
            'top' => $points >= 48 && $level1BasicCount >= 3 && $branchesWithKey >= 2,
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
     * Promuove l'agente alla qualifica piu' alta soddisfatta, se superiore
     * all'attuale. Registra lo storico e l'audit log. Restituisce true se
     * e' avvenuta una promozione.
     */
    public function promoteIfEligible(User $agent): bool
    {
        $evaluation = $this->evaluate($agent);
        $eligibleRank = $evaluation['eligible_rank'];

        if ($this->rankLevel($eligibleRank) <= $this->rankLevel($agent->mlm_rank)) {
            return false;
        }

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
            'event' => 'mlm.rank_promoted',
            'auditable_type' => User::class,
            'auditable_id' => $agent->id,
            'context' => [
                'previous_rank' => $previousRank,
                'new_rank' => $eligibleRank,
                'evaluation' => $evaluation,
            ],
        ]);

        return true;
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
                ['label' => 'Basic al 1° livello', 'required' => 4, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con >= 300 punti', 'required' => 3, 'current' => $evaluation['branches_300pt']],
            ],
            'top' => [
                ['label' => 'Punti attivi', 'required' => 48, 'current' => $evaluation['points']],
                ['label' => 'Basic al 1° livello', 'required' => 3, 'current' => $evaluation['level1_basic_count']],
                ['label' => 'Colonne con almeno un Key+', 'required' => 2, 'current' => $evaluation['branches_with_key']],
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
