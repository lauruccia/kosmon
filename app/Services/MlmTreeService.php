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

    /**
     * Sottoalbero completo di un agente come array annidato, pronto per il
     * rendering dell'albero (portale "Struttura" e admin "Albero agenti").
     * Ogni nodo: id, name, rank, points (attivi oggi), clients_count,
     * agents_count (figli diretti), children[].
     */
    public function subtree(User $root): array
    {
        $ids = MlmAgentClosure::where('ancestor_id', $root->id)->pluck('descendant_id');

        $parentMap = MlmAgentClosure::whereIn('descendant_id', $ids)
            ->where('depth', 1)
            ->pluck('ancestor_id', 'descendant_id');

        $users = User::whereIn('id', $ids)
            ->get(['id', 'name', 'mlm_rank', 'mlm_activated_at'])
            ->keyBy('id');

        $clientCounts = User::whereIn('mlm_client_agent_id', $ids)
            ->selectRaw('mlm_client_agent_id, count(*) as total')
            ->groupBy('mlm_client_agent_id')
            ->pluck('total', 'mlm_client_agent_id');

        $points = DB::table('mlm_point_ledger')
            ->whereIn('agent_user_id', $ids)
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->whereDate('valid_until', '>=', now()->toDateString())
            ->selectRaw('agent_user_id, sum(points) as pts')
            ->groupBy('agent_user_id')
            ->pluck('pts', 'agent_user_id');

        $childrenByParent = [];
        foreach ($parentMap as $child => $parent) {
            $childrenByParent[$parent][] = $child;
        }

        $build = function (int $id) use (&$build, $users, $clientCounts, $points, $childrenByParent): ?array {
            $user = $users->get($id);
            if (! $user) {
                return null;
            }

            $children = [];
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $node = $build($childId);
                if ($node) {
                    $children[] = $node;
                }
            }
            usort($children, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

            return [
                'id'            => $user->id,
                'name'          => $user->name,
                'rank'          => $user->mlm_rank ?: 'start',
                'points'        => (int) ($points[$id] ?? 0),
                'clients_count' => (int) ($clientCounts[$id] ?? 0),
                'agents_count'  => count($children),
                'children'      => $children,
            ];
        };

        return $build($root->id) ?? [];
    }

    /**
     * Sponsor attuale (antenato diretto, depth 1) nell'albero MLM. Riflette
     * sempre la posizione corrente, anche dopo un moveAgent() — NON usare
     * $user->referredBy per mostrare lo sponsor nell'albero: e' il referral
     * di registrazione e puo' divergere dallo sponsor reale dopo uno spostamento.
     */
    public function currentSponsor(User $agent): ?User
    {
        $row = MlmAgentClosure::where('descendant_id', $agent->id)->where('depth', 1)->first();

        return $row ? User::find($row->ancestor_id) : null;
    }

    /** Agenti radice (senza sponsor nell'albero MLM), per la vista admin. */
    public function rootAgents(): Collection
    {
        $withSponsor = MlmAgentClosure::where('depth', 1)->pluck('descendant_id');

        return User::where('mlm_role', 'agente')
            ->whereNotIn('id', $withSponsor)
            ->orderBy('name')
            ->get();
    }

    /**
     * Sposta un agente (e tutto il suo sottoalbero) sotto un nuovo sponsor,
     * ricollegando la closure table. Operazione puramente STRUTTURALE: punti,
     * commissioni e bonus gia' calcolati restano quelli storici (calcolati
     * con la posizione precedente); le prossime valutazioni (qualifiche,
     * commissioni indirette, cascata bonus) leggono l'albero corrente e
     * quindi rifletteranno automaticamente la nuova posizione da qui in poi.
     *
     * $newSponsor = null sposta l'agente in radice (nessuno sponsor).
     *
     * @throws \InvalidArgumentException se lo spostamento e' invalido (self, ciclo, non agente).
     */
    public function moveAgent(User $agent, ?User $newSponsor, ?User $actor = null): void
    {
        abort_unless($agent->isMlmAgent(), 422, 'Solo un agente puo\' essere spostato nell\'albero MLM.');

        if ($newSponsor) {
            abort_unless($newSponsor->isMlmAgent(), 422, 'Il nuovo sponsor deve essere un agente MLM attivo.');

            if ($newSponsor->id === $agent->id) {
                throw new \InvalidArgumentException('Un agente non puo\' essere sponsor di se stesso.');
            }

            $newSponsorIsDescendant = MlmAgentClosure::where('ancestor_id', $agent->id)
                ->where('descendant_id', $newSponsor->id)
                ->exists();

            if ($newSponsorIsDescendant) {
                throw new \InvalidArgumentException('Non puoi spostare un agente sotto un suo discendente: creerebbe un ciclo nell\'albero.');
            }
        }

        DB::transaction(function () use ($agent, $newSponsor, $actor): void {
            $oldSponsorRow = MlmAgentClosure::where('descendant_id', $agent->id)->where('depth', 1)->first();
            $oldSponsorId = $oldSponsorRow?->ancestor_id;

            if ($newSponsor && $oldSponsorId === $newSponsor->id) {
                return; // Nessun cambiamento: stesso sponsor attuale.
            }

            // Sottoalbero completo dell'agente (lui incluso, depth relativa a se' stesso).
            $subtreeRows = MlmAgentClosure::where('ancestor_id', $agent->id)->get(['descendant_id', 'depth']);
            $subtreeIds = $subtreeRows->pluck('descendant_id');

            // Tutti gli antenati ATTUALI dell'agente (esclude l'agente stesso): il "taglio"
            // rimuove ogni riga che collegava questi antenati a QUALSIASI nodo del sottoalbero.
            $oldUplineIds = MlmAgentClosure::where('descendant_id', $agent->id)
                ->where('depth', '>', 0)
                ->pluck('ancestor_id');

            if ($oldUplineIds->isNotEmpty()) {
                MlmAgentClosure::whereIn('ancestor_id', $oldUplineIds)
                    ->whereIn('descendant_id', $subtreeIds)
                    ->delete();
            }

            if ($newSponsor) {
                // Antenati del nuovo sponsor (lui incluso, a depth 0): per ognuno,
                // ricreiamo il collegamento verso OGNI nodo del sottoalbero spostato.
                $newAncestorRows = MlmAgentClosure::where('descendant_id', $newSponsor->id)
                    ->get(['ancestor_id', 'depth', 'branch_root_id']);

                $now = now();
                $insertRows = [];

                foreach ($newAncestorRows as $r1) {
                    foreach ($subtreeRows as $r2) {
                        $insertRows[] = [
                            'ancestor_id'    => $r1->ancestor_id,
                            'descendant_id'  => $r2->descendant_id,
                            'depth'          => $r1->depth + 1 + $r2->depth,
                            'branch_root_id' => $r1->depth === 0 ? $agent->id : $r1->branch_root_id,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                    }
                }

                foreach (array_chunk($insertRows, 500) as $chunk) {
                    MlmAgentClosure::insert($chunk);
                }
            }

            AuditLog::create([
                'actor_user_id'   => $actor?->id,
                'event'           => 'mlm.agent_moved',
                'auditable_type'  => User::class,
                'auditable_id'    => $agent->id,
                'context'         => [
                    'old_sponsor_id' => $oldSponsorId,
                    'new_sponsor_id' => $newSponsor?->id,
                    'subtree_size'   => $subtreeIds->count(),
                ],
            ]);
        });
    }
}
