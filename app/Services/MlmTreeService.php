<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MlmAgentClosure;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gestisce l'albero degli agenti MLM (closure table `mlm_agent_closure`) e il
 * collegamento dei clienti al primo agente antenato ("agente risolto").
 *
 * Vedi MLM_PROPOSAL.md §3 e §8 per il modello concettuale.
 */
class MlmTreeService
{
    /**
     * Inserisce un nuovo agente nell'albero MLM sotto lo sponsor indicato
     * (null = radice del proprio albero, es. il primo agente in assoluto).
     */
    public function attachAgent(User $agent, ?User $sponsor): void
    {
        DB::transaction(function () use ($agent, $sponsor): void {
            // Riga self (depth 0): ogni agente è antenato di se stesso.
            MlmAgentClosure::create([
                'ancestor_id'    => $agent->id,
                'descendant_id'  => $agent->id,
                'depth'          => 0,
                'branch_root_id' => null,
            ]);

            if (! $sponsor) {
                return;
            }

            // Per ogni antenato dello sponsor (incluso lo sponsor stesso, depth 0),
            // aggiungiamo una riga verso il nuovo agente con depth+1. La "colonna"
            // (branch_root_id) si eredita dall'antenato, tranne per lo sponsor
            // stesso: da lì in poi il nuovo agente E' la colonna.
            $sponsorAncestorRows = MlmAgentClosure::where('descendant_id', $sponsor->id)->get();

            foreach ($sponsorAncestorRows as $row) {
                MlmAgentClosure::create([
                    'ancestor_id'    => $row->ancestor_id,
                    'descendant_id'  => $agent->id,
                    'depth'          => $row->depth + 1,
                    'branch_root_id' => $row->depth === 0 ? $agent->id : $row->branch_root_id,
                ]);
            }

            AuditLog::create([
                'actor_user_id'   => $agent->id,
                'event'           => 'mlm.agent_attached',
                'auditable_type'  => User::class,
                'auditable_id'    => $agent->id,
                'context'         => ['sponsor_user_id' => $sponsor->id],
            ]);
        });
    }

    /**
     * Risolve l'agente a cui attribuire punti/commissioni per un nuovo cliente,
     * a partire da chi lo ha invitato (referrer). Regola confermata: se il
     * referrer è a sua volta un cliente, si risale al SUO agente già risolto
     * (propagazione O(1), l'invariante è mantenuta ad ogni registrazione).
     * Restituisce null se non c'è nessun agente nella catena (es. invito "orfano").
     */
    public function resolveAgentForNewClient(?User $referrer): ?User
    {
        if (! $referrer) {
            return null;
        }

        if ($referrer->isMlmAgent()) {
            return $referrer;
        }

        return $referrer->mlmClientAgent ?? null;
    }

    /**
     * Catena di antenati di un agente, dal PIU' VICINO (sponsor diretto,
     * depth 1) al PIU' LONTANO (radice dell'albero). Usata dalla cascata
     * bonus per risalire la struttura a partire da chi diventa BasiQ.
     */
    public function orderedUpline(User $agent): Collection
    {
        $rows = MlmAgentClosure::where('descendant_id', $agent->id)
            ->where('depth', '>', 0)
            ->orderBy('depth')
            ->pluck('ancestor_id');

        $usersById = User::whereIn('id', $rows)->get()->keyBy('id');

        return $rows->map(fn (int $id) => $usersById->get($id))->filter()->values();
    }

    /** Discendenti diretti (depth 1) di un agente nell'albero MLM. */
    public function directDownline(User $agent): Collection
    {
        $ids = MlmAgentClosure::where('ancestor_id', $agent->id)
            ->where('depth', 1)
            ->pluck('descendant_id');

        return User::whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * Riepilogo per colonna/ramo sotto un agente: per ciascun figlio diretto
     * (branch root), numero di agenti nel ramo, distribuzione dei rank presenti
     * e somma dei punti attivi degli agenti del ramo (utile per i requisiti
     * "N colonne da X punti" e "N Key/Senior/Top su colonne diverse").
     */
    public function branchSummaries(User $agent): Collection
    {
        $branches = $this->directDownline($agent);

        return $branches->map(function (User $branchRoot) use ($agent) {
            $descendantIds = MlmAgentClosure::where('ancestor_id', $agent->id)
                ->where('branch_root_id', $branchRoot->id)
                ->pluck('descendant_id');

            $membersQuery = User::whereIn('id', $descendantIds);

            $rankCounts = (clone $membersQuery)
                ->selectRaw('mlm_rank, count(*) as total')
                ->groupBy('mlm_rank')
                ->pluck('total', 'mlm_rank');

            $activePoints = (int) DB::table('mlm_point_ledger')
                ->whereIn('agent_user_id', $descendantIds)
                ->whereDate('valid_from', '<=', now()->toDateString())
                ->whereDate('valid_until', '>=', now()->toDateString())
                ->sum('points');

            return [
                'branch_root'   => $branchRoot,
                'agent_count'   => $descendantIds->count(),
                'rank_counts'   => $rankCounts,
                'active_points' => $activePoints,
            ];
        });
    }
}
