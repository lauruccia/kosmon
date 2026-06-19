<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNettingProposalRequest;
use App\Models\Account;
use App\Models\NettingProposal;
use App\Services\NettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NettingController extends Controller
{
    public function __construct(private readonly NettingService $service) {}

    /** Lista compensazioni dell'account corrente. */
    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $asProposer = NettingProposal::query()
            ->with(['proposerAccount', 'counterpartyAccount', 'netTransfer'])
            ->where('proposer_account_id', $currentAccount->id)
            ->orderByDesc('id')
            ->get();

        $asCounterparty = NettingProposal::query()
            ->with(['proposerAccount', 'counterpartyAccount', 'netTransfer'])
            ->where('counterparty_account_id', $currentAccount->id)
            ->orderByDesc('id')
            ->get();

        $pendingAction = $asCounterparty->where('status', 'pending')->count();

        return view('portal.netting.index', [
            'pageTitle'      => 'Compensazione crediti',
            'activeNav'      => 'netting',
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'asProposer'     => $asProposer,
            'asCounterparty' => $asCounterparty,
            'pendingAction'  => $pendingAction,
        ]);
    }

    /** Form per creare una proposta. */
    public function create(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $counterpartyAccounts = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($currentAccount->id)
            ->orderBy('id')
            ->get();

        return view('portal.netting.create', [
            'pageTitle'            => 'Nuova compensazione',
            'activeNav'            => 'netting',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $currentUser,
            'counterpartyAccounts' => $counterpartyAccounts,
        ]);
    }

    /** AJAX/HTMX: carica i trasferimenti pendenti tra due account. */
    public function loadTransfers(Request $request): \Illuminate\Http\JsonResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $counterpartyId = $request->integer('counterparty_account_id');
        if (! $counterpartyId) {
            return response()->json(['proposer' => [], 'counterparty' => []]);
        }

        $counterparty = Account::findOrFail($counterpartyId);

        [$proposerCredits, $counterpartyCredits] = $this->service->getMutualPendingTransfers(
            $currentAccount,
            $counterparty,
        );

        $mapTransfer = fn ($t) => [
            'id'          => $t->id,
            'reference'   => $t->reference,
            'amount'      => $t->amount,
            'description' => $t->description,
            'created_at'  => $t->created_at->format('d/m/Y'),
            'kind'        => $t->kind,
        ];

        return response()->json([
            'proposer'     => $proposerCredits->map($mapTransfer)->values(),
            'counterparty' => $counterpartyCredits->map($mapTransfer)->values(),
        ]);
    }

    /** Salva la proposta di compensazione. */
    public function store(StoreNettingProposalRequest $request): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $validated = $request->validated();

        $proposerIds      = array_map('intval', $validated['proposer_transfer_ids'] ?? []);
        $counterpartyIds  = array_map('intval', $validated['counterparty_transfer_ids'] ?? []);

        if (empty($proposerIds) && empty($counterpartyIds)) {
            return back()->withInput()->with('portal_error', 'Seleziona almeno un trasferimento da compensare.');
        }

        try {
            $proposal = $this->service->propose(
                proposerAccountId:       $currentAccount->id,
                counterpartyAccountId:   (int) $validated['counterparty_account_id'],
                proposerTransferIds:     $proposerIds,
                counterpartyTransferIds: $counterpartyIds,
                proposedBy:             $currentUser->id,
                description:            $validated['description'] ?? null,
                ipAddress:              $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return redirect()
            ->route('portal.netting.show', $proposal)
            ->with('portal_success', 'Proposta di compensazione inviata. La controparte riceverà una notifica.');
    }

    /** Dettaglio proposta. */
    public function show(Request $request, NettingProposal $nettingProposal): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless(
            $nettingProposal->proposer_account_id === $currentAccount->id ||
            $nettingProposal->counterparty_account_id === $currentAccount->id,
            403
        );

        $nettingProposal->load([
            'proposerAccount.company',
            'counterpartyAccount.company',
            'netPayerAccount',
            'netTransfer',
            'proposedBy',
            'actionedBy',
        ]);

        $proposerTransfers     = $nettingProposal->proposerTransfers();
        $counterpartyTransfers = $nettingProposal->counterpartyTransfers();

        $isCounterparty = $nettingProposal->counterparty_account_id === $currentAccount->id;

        return view('portal.netting.show', [
            'pageTitle'             => 'Compensazione #' . $nettingProposal->id,
            'activeNav'            => 'netting',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $currentUser,
            'proposal'             => $nettingProposal,
            'isCounterparty'       => $isCounterparty,
            'proposerTransfers'    => $proposerTransfers,
            'counterpartyTransfers' => $counterpartyTransfers,
        ]);
    }

    /** Accetta la proposta (solo counterparty). */
    public function accept(Request $request, NettingProposal $nettingProposal): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless($nettingProposal->counterparty_account_id === $currentAccount->id, 403);
        abort_unless($nettingProposal->isPending(), 422);

        try {
            $this->service->accept($nettingProposal, $currentUser->id, $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return redirect()
            ->route('portal.netting.show', $nettingProposal)
            ->with('portal_success', 'Compensazione accettata. I crediti incrociati sono stati compensati.');
    }

    /** Rifiuta la proposta (solo counterparty). */
    public function reject(Request $request, NettingProposal $nettingProposal): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless($nettingProposal->counterparty_account_id === $currentAccount->id, 403);
        abort_unless($nettingProposal->isPending(), 422);

        try {
            $this->service->reject($nettingProposal, $currentUser->id, $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return redirect()
            ->route('portal.netting.show', $nettingProposal)
            ->with('portal_success', 'Proposta rifiutata. I tuoi crediti in sospeso restano invariati.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function resolveCurrentContext(\App\Models\User $viewer, ?int $requestedCompanyId = null): array
    {
        abort_if($viewer->canAccessBackoffice(), 403);

        if ($viewer->managed_account_id !== null) {
            $currentAccount = \App\Models\Account::query()
                ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
                ->whereKey($viewer->managed_account_id)
                ->firstOrFail();
            return [$currentAccount, $viewer, $currentAccount->parentAccount ?? $currentAccount];
        }

        if ($viewer->company_id !== null) {
            abort_unless($requestedCompanyId === null || $requestedCompanyId === $viewer->company_id, 403);
            $currentAccount = \App\Models\Account::query()
                ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
                ->where('company_id', $viewer->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
            return [$currentAccount, $viewer, $currentAccount];
        }

        $currentAccount = \App\Models\Account::query()
            ->with(['company', 'ownerUser', 'creditLimits', 'parentAccount'])
            ->where('owner_user_id', $viewer->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();
        return [$currentAccount, $viewer, $currentAccount];
    }

    private function requestedCompanyId(Request $request): ?int
    {
        return $request->filled('company_id') ? $request->integer('company_id') : null;
    }
}
