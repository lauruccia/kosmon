<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmAgentClosure;
use App\Models\User;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Backoffice MLM — vista di sola lettura sull'albero agenti (Fase 1).
 * Le fasi successive (punti, qualifiche automatiche, commissioni, bonus,
 * payout) aggiungeranno azioni qui. Vedi MLM_PROPOSAL.md.
 */
class MlmController extends Controller
{
    use AuthorizesBackoffice;

    public function index(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $search = trim((string) $request->query('q', ''));
        $rankFilter = $request->query('rank', '');

        $agents = User::query()
            ->where('mlm_role', 'agente')
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($rankFilter, fn ($q) => $q->where('mlm_rank', $rankFilter))
            ->withCount('mlmClients')
            ->orderByDesc('mlm_activated_at')
            ->paginate(30)->withQueryString();

        $clientsCount = User::where('mlm_role', 'cliente')->count();
        $unattachedClientsCount = User::where('mlm_role', 'cliente')->whereNull('mlm_client_agent_id')->count();

        return view('admin.mlm.index', [
            'pageTitle' => 'MLM — Agenti',
            'agents' => $agents,
            'filters' => ['q' => $search, 'rank' => $rankFilter],
            'ranks' => User::MLM_RANK_ORDER,
            'clientsCount' => $clientsCount,
            'unattachedClientsCount' => $unattachedClientsCount,
            'activeNav' => 'mlm',
        ]);
    }

    public function show(Request $request, User $user, MlmTreeService $tree, MlmRankEngine $rankEngine): View
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        $branches = $tree->branchSummaries($user);
        $evaluation = $rankEngine->evaluate($user);

        $rankHistory = $user->mlmRankHistory()->orderByDesc('achieved_at')->get();
        $nextRank = $rankEngine->nextRankRequirements($user);

        $bonusPayouts = $user->mlmBonusPayouts()
            ->with('event.basiqUser:id,name,email')
            ->latest()
            ->take(20)
            ->get();

        $clients = $user->mlmClients()
            ->select('id', 'name', 'email', 'created_at')
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'clients_page');

        $pointLedger = $user->mlmPointLedgerEntries()
            ->with('client:id,name,email')
            ->latest()
            ->take(20)
            ->get();

        return view('admin.mlm.show', [
            'pageTitle' => 'MLM — ' . $user->name,
            'agent' => $user,
            'branches' => $branches,
            'clients' => $clients,
            'pointLedger' => $pointLedger,
            'rankHistory' => $rankHistory,
            'evaluation' => $evaluation,
            'nextRank' => $nextRank,
            'bonusPayouts' => $bonusPayouts,
            'sponsor' => $tree->currentSponsor($user),
            'activeNav' => 'mlm',
        ]);
    }

    /**
     * Albero agenti navigabile: senza {user} mostra le radici (forest),
     * con {user} il sottoalbero di quell'agente. Cliccando un nodo si
     * naviga all'albero di quello specifico agente.
     */
    public function tree(Request $request, MlmTreeService $treeService, ?User $user = null): View
    {
        $this->authorizeBackoffice($request->user());

        if ($user) {
            abort_unless($user->isMlmAgent(), 404);

            return view('admin.mlm.tree', [
                'pageTitle' => 'Albero — ' . $user->name,
                'root'      => $user,
                'tree'      => $treeService->subtree($user),
                'roots'     => null,
                'sponsor'   => $treeService->currentSponsor($user),
                'activeNav' => 'mlm',
            ]);
        }

        return view('admin.mlm.tree', [
            'pageTitle' => 'Albero agenti',
            'root'      => null,
            'tree'      => null,
            'roots'     => $treeService->rootAgents(),
            'sponsor'   => null,
            'activeNav' => 'mlm',
        ]);
    }

    /**
     * GET /admin/mlm-albero/{user}/sposta
     * Form di ricerca del nuovo sponsor per spostare un agente nell'albero.
     */
    public function moveForm(Request $request, User $user, MlmTreeService $treeService): View
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        $search = trim((string) $request->query('q', ''));

        $descendantIds = MlmAgentClosure::where('ancestor_id', $user->id)->pluck('descendant_id');

        $candidates = User::query()
            ->where('mlm_role', 'agente')
            ->where('id', '!=', $user->id)
            ->whereNotIn('id', $descendantIds)
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        return view('admin.mlm.move', [
            'pageTitle'  => 'Sposta ' . $user->name,
            'agent'      => $user,
            'sponsor'    => $treeService->currentSponsor($user),
            'candidates' => $candidates,
            'search'     => $search,
            'activeNav'  => 'mlm',
        ]);
    }

    /**
     * POST /admin/mlm-albero/{user}/sposta
     * Esegue lo spostamento: ricollega l'agente (e il suo sottoalbero) al
     * nuovo sponsor scelto, oppure lo porta in radice se new_sponsor_id
     * e' vuoto. Vedi MlmTreeService::moveAgent() per i dettagli — operazione
     * puramente strutturale, non tocca punti/commissioni/bonus gia' calcolati.
     */
    public function move(Request $request, User $user, MlmTreeService $treeService, MlmRankEngine $rankEngine): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        $validated = $request->validate([
            'new_sponsor_id'    => ['nullable', 'integer', 'exists:users,id'],
            'reevaluate_ranks'  => ['nullable', 'boolean'],
        ]);

        $newSponsor = ! empty($validated['new_sponsor_id'])
            ? User::findOrFail($validated['new_sponsor_id'])
            : null;

        // Upline PRECEDENTE, catturata prima dello spostamento: con la
        // retrocessione automatica (2026-07-13) anche chi PERDE il ramo
        // spostato va rivalutato (es. sponsor che scende sotto i "2 Basic
        // al 1° livello").
        $oldUpline = $treeService->orderedUpline($user);

        try {
            $treeService->moveAgent($user, $newSponsor, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['new_sponsor_id' => $e->getMessage()]);
        }

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.mlm.agent_moved',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['new_sponsor_id' => $newSponsor?->id],
        ]);

        // Opzionale: valuta subito le qualifiche dell'agente spostato, del suo
        // sottoalbero, della nuova upline E della vecchia upline (che con la
        // retrocessione automatica puo' perdere requisiti), invece di
        // aspettare il job notturno mlm:recalculate-points. NON ricalcola/
        // riscrive commissioni o bonus gia' generati: quelli restano storici
        // per costruzione (vedi MlmTreeService::moveAgent()).
        if ($request->boolean('reevaluate_ranks')) {
            $toEvaluate = collect([$user])
                ->merge($treeService->orderedUpline($newSponsor ?? $user))
                ->when($newSponsor, fn ($c) => $c->push($newSponsor))
                ->merge($oldUpline);

            $descendantIds = MlmAgentClosure::where('ancestor_id', $user->id)
                ->where('descendant_id', '!=', $user->id)
                ->pluck('descendant_id');

            $toEvaluate = $toEvaluate->merge(User::whereIn('id', $descendantIds)->get())
                ->unique('id');

            foreach ($toEvaluate as $candidate) {
                $rankEngine->syncRank($candidate);
            }
        }

        return redirect()->route('admin.mlm.tree', $user)
            ->with('portal_success', $user->name . ' e\' stato spostato nell\'albero MLM.');
    }
}
