<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MlmAgentClosure;
use App\Models\User;
use App\Services\MlmRankEngine;
use App\Services\MlmTreeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        // Requisiti NON piu' soddisfatti del grado attuale (2026-07-22,
        // richiesta di Laura dopo il requisito clienti): se valorizzato,
        // l'agente verra' retrocesso al prossimo ricalcolo.
        $retention = $rankEngine->currentRankRetention($user);

        $bonusPayouts = $user->mlmBonusPayouts()
            ->with('event.basiqUser:id,name,email')
            ->latest()
            ->take(20)
            ->get();

        $agentCode = $user->agentCode();

        $clients = $user->mlmClients()
            ->select('id', 'name', 'email', 'created_at')
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'clients_page');

        $pointLedger = $user->mlmPointLedgerEntries()
            ->with('client:id,name,email')
            ->latest()
            ->take(20)
            ->get();

        $metricGrants = $user->mlmMetricGrants()
            ->with('grantedBy:id,name,email', 'revokedBy:id,name,email')
            ->latest()
            ->take(20)
            ->get();

        return view('admin.mlm.show', [
            'pageTitle' => 'MLM — ' . $user->name,
            'agent' => $user,
            'agentCode' => $agentCode,
            'branches' => $branches,
            'clients' => $clients,
            'pointLedger' => $pointLedger,
            'metricGrants' => $metricGrants,
            'rankHistory' => $rankHistory,
            'evaluation' => $evaluation,
            'nextRank' => $nextRank,
            'retention' => $retention,
            'bonusPayouts' => $bonusPayouts,
            'sponsor' => $tree->currentSponsor($user),
            'activeNav' => 'mlm',
        ]);
    }

    /**
     * Pagina dedicata "Promuovi agente": assegna punti/agenti omaggio a un
     * singolo agente (scorciatoia rispetto alla selezione multipla sull'indice
     * MLM), riusando lo stesso endpoint di store di MlmMetricGrantController.
     */
    public function promoteForm(Request $request, User $user, MlmRankEngine $rankEngine): View
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmAgent(), 404);

        return view('admin.mlm.promote', [
            'pageTitle' => 'Promuovi ' . $user->name,
            'agent' => $user,
            'evaluation' => $rankEngine->evaluate($user),
            'nextRank' => $rankEngine->nextRankRequirements($user),
            'retention' => $rankEngine->currentRankRetention($user),
            'activeNav' => 'mlm',
        ]);
    }

    /**
     * Albero agenti navigabile. Con la regola "radice unica" (2026-07-15):
     * senza {user}, se e' gia' stata designata una radice di sistema
     * (Impostazioni MLM → Agente radice), si va dritti al suo albero;
     * altrimenti (bootstrap, nessuna radice ancora scelta) si mostra la
     * lista delle radici indipendenti esistenti, cosi' l'admin puo'
     * sceglierne una da Impostazioni MLM. Con {user} mostra il sottoalbero
     * di quell'agente. Cliccando un nodo si naviga all'albero di quello
     * specifico agente.
     */
    public function tree(Request $request, MlmTreeService $treeService, ?User $user = null): View|RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        if ($user) {
            abort_unless($user->isMlmAgent(), 404);

            $systemRoot = $treeService->systemRootAgent();

            return view('admin.mlm.tree', [
                'pageTitle'  => 'Albero — ' . $user->name,
                'root'       => $user,
                'tree'       => $treeService->subtree($user),
                'roots'      => null,
                'sponsor'    => $treeService->currentSponsor($user),
                'systemRoot' => $systemRoot,
                'activeNav'  => 'mlm',
            ]);
        }

        $systemRoot = $treeService->systemRootAgent();
        if ($systemRoot) {
            return redirect()->route('admin.mlm.tree', $systemRoot);
        }

        return view('admin.mlm.tree', [
            'pageTitle'  => 'Albero agenti',
            'root'       => null,
            'tree'       => null,
            'roots'      => $treeService->rootAgents(),
            'sponsor'    => null,
            'systemRoot' => null,
            'activeNav'  => 'mlm',
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

        $systemRoot = $treeService->systemRootAgent();

        return view('admin.mlm.move', [
            'pageTitle'     => 'Sposta ' . $user->name,
            'agent'         => $user,
            'sponsor'       => $treeService->currentSponsor($user),
            'candidates'    => $candidates,
            'search'        => $search,
            // Con la regola "radice unica" (2026-07-15), "Nessuno sponsor" è
            // permesso solo per l'agente che è già la radice di sistema (o
            // quando non ne è ancora stata designata nessuna).
            'canBecomeRoot' => ! $systemRoot || $systemRoot->id === $user->id,
            'activeNav'     => 'mlm',
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

    /**
     * GET /admin/mlm/clienti/{user}/riassegna
     * Form di ricerca del nuovo agente di riferimento per un CLIENTE
     * (2026-07-27, richiesta di Laura, punto 2). A differenza dello
     * spostamento di un agente (move/moveForm), qui non esiste rischio di
     * cicli: il cliente non fa parte dell'albero MLM, quindi tutti gli
     * agenti attivi sono candidati validi.
     */
    public function reassignClientForm(Request $request, User $user, MlmTreeService $treeService): View
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmClient(), 404);

        $search = trim((string) $request->query('q', ''));

        $candidates = User::query()
            ->where('mlm_role', 'agente')
            ->when($search, fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)->withQueryString();

        return view('admin.mlm.reassign-client', [
            'pageTitle'    => 'Riassegna ' . $user->name,
            'client'       => $user,
            'currentAgent' => $user->mlmClientAgent,
            'candidates'   => $candidates,
            'search'       => $search,
            'activeNav'    => 'mlm',
        ]);
    }

    /**
     * POST /admin/mlm/clienti/{user}/riassegna
     * Esegue la riassegnazione del cliente al nuovo agente scelto (o lo
     * rende "non attribuito" se new_agent_id e' vuoto). Vedi
     * MlmTreeService::reassignClient() — operazione puramente strutturale,
     * non tocca punti/commissioni/bonus gia' generati.
     */
    public function reassignClient(Request $request, User $user, MlmTreeService $treeService): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        abort_unless($user->isMlmClient(), 404);

        $validated = $request->validate([
            'new_agent_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $newAgent = ! empty($validated['new_agent_id'])
            ? User::findOrFail($validated['new_agent_id'])
            : null;

        $treeService->reassignClient($user, $newAgent, $request->user());

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.mlm.client_reassigned',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['new_agent_id' => $newAgent?->id],
        ]);

        $redirectTo = $newAgent
            ? route('admin.mlm.show', $newAgent)
            : route('admin.mlm.index');

        return redirect($redirectTo)
            ->with('portal_success', $user->name . ' e\' stato riassegnato' . ($newAgent ? ' a ' . $newAgent->name . '.' : ': ora non e\' attribuito a nessun agente.'));
    }

    // ── Assegnazione clienti in blocco ────────────────────────────────────
    // (2026-08-13, richiesta di Laura: dopo l'importazione di tutti i clienti
    // e conti da un altro sistema, servono modi rapidi per assegnarli
    // manualmente ai rispettivi agenti invece di riassegnarli uno alla volta
    // da reassignClient() sopra.)

    /**
     * GET /admin/mlm/assegnazione-clienti
     * Elenco clienti filtrabile (per nome/email/azienda e per agente attuale,
     * con scorciatoia "non assegnati") con selezione multipla per
     * l'assegnazione in blocco.
     */
    public function clientAssignments(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->clientAssignmentFilters($request);

        $clients = $this->clientAssignmentQuery($filters)
            ->with(['company:id,name', 'mlmClientAgent:id,name,email'])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.mlm.client-assignments', [
            'pageTitle'       => 'Assegnazione clienti agli agenti',
            'clients'         => $clients,
            'agents'          => User::where('mlm_role', 'agente')->orderBy('name')->get(['id', 'name', 'email']),
            'filters'         => $filters,
            'unattachedCount' => User::where('mlm_role', 'cliente')->whereNull('mlm_client_agent_id')->count(),
            'activeNav'       => 'mlm',
        ]);
    }

    /**
     * POST /admin/mlm/assegnazione-clienti
     * Assegna in blocco un agente ai clienti selezionati (scope=selected)
     * oppure a tutti i clienti che rispettano i filtri correnti
     * (scope=all_filtered) — stessa struttura di CompanyController::bulkAction().
     * Ogni assegnazione elementare passa da MlmTreeService::reassignClient()
     * (che gia' scrive il proprio AuditLog "mlm.client_reassigned" per
     * cliente); qui in piu' scriviamo un unico AuditLog di riepilogo per
     * l'operazione in blocco.
     */
    public function bulkAssignClients(Request $request, MlmTreeService $treeService): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'new_agent_id'  => ['required', 'integer', 'exists:users,id'],
            'scope'         => ['required', 'in:selected,all_filtered'],
            'client_ids'    => ['array'],
            'client_ids.*'  => ['integer'],
        ]);

        $newAgent = User::findOrFail($validated['new_agent_id']);
        abort_unless($newAgent->isMlmAgent(), 422, 'Il destinatario selezionato non e\' un agente MLM.');

        if ($validated['scope'] === 'all_filtered') {
            $query = $this->clientAssignmentQuery($this->clientAssignmentFilters($request));
        } else {
            $ids = $validated['client_ids'] ?? [];
            if ($ids === []) {
                return back()->with('portal_error', 'Nessun cliente selezionato.');
            }
            $query = User::query()->where('mlm_role', 'cliente')->whereKey($ids);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            return back()->with('portal_error', 'Nessun cliente da assegnare (controlla i filtri).');
        }

        $actor = $request->user();
        $count = 0;

        DB::transaction(function () use ($clients, $newAgent, $treeService, $actor, &$count): void {
            foreach ($clients as $client) {
                if ((int) $client->mlm_client_agent_id !== $newAgent->id) {
                    $treeService->reassignClient($client, $newAgent, $actor);
                    $count++;
                }
            }
        });

        if ($count > 0) {
            AuditLog::create([
                'actor_user_id'  => $actor->id,
                'event'          => 'admin.mlm.clients_bulk_assigned',
                'auditable_type' => User::class,
                'auditable_id'   => $newAgent->id,
                'context'        => [
                    'new_agent_id' => $newAgent->id,
                    'client_ids'   => $clients->pluck('id')->all(),
                    'count'        => $count,
                ],
            ]);
        }

        return back()->with('portal_success', $count > 0
            ? ($count === 1
                ? '1 cliente assegnato a ' . $newAgent->name . '.'
                : "{$count} clienti assegnati a " . $newAgent->name . '.')
            : 'Nessuna modifica: i clienti selezionati erano gia\' assegnati a ' . $newAgent->name . '.');
    }

    /** Filtri della pagina di assegnazione clienti, letti dalla query string. */
    private function clientAssignmentFilters(Request $request): array
    {
        return [
            'q'     => trim((string) $request->query('q', '')),
            // '' = tutti, 'none' = solo non assegnati, altrimenti id agente.
            'agent' => trim((string) $request->query('agent', '')),
        ];
    }

    /** Query clienti (mlm_role=cliente) con i filtri della pagina applicati. */
    private function clientAssignmentQuery(array $filters): Builder
    {
        return User::query()
            ->where('mlm_role', 'cliente')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters): void {
                $s = $filters['q'];
                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$s}%")));
            })
            ->when($filters['agent'] === 'none', fn (Builder $q) => $q->whereNull('mlm_client_agent_id'))
            ->when(
                $filters['agent'] !== '' && $filters['agent'] !== 'none' && ctype_digit($filters['agent']),
                fn (Builder $q) => $q->where('mlm_client_agent_id', (int) $filters['agent'])
            );
    }
}
