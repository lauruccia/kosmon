<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
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
            'sponsor' => $user->referredBy,
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
                'sponsor'   => $user->referredBy?->isMlmAgent() ? $user->referredBy : null,
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
}
