<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\TextPaymentRequest;
use App\Models\User;
use App\Notifications\TextPaymentRequestApprovedNotification;
use App\Notifications\TextPaymentRequestRejectedNotification;
use App\Notifications\TextPaymentRequestSentNotification;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TextPaymentRequestController extends Controller
{
    // ── Index (inviate + ricevute) ─────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $sent = TextPaymentRequest::query()
            ->with(['toAccount.company', 'toAccount.ownerUser'])
            ->where('from_account_id', $currentAccount->id)
            ->latest()
            ->paginate(15, ['*'], 'sent_page');

        $received = TextPaymentRequest::query()
            ->with(['fromAccount.company', 'fromAccount.ownerUser'])
            ->where('to_account_id', $currentAccount->id)
            ->latest()
            ->paginate(15, ['*'], 'recv_page');

        return view('portal.text-requests.index', [
            'pageTitle'      => 'Richieste di pagamento',
            'currentAccount' => $currentAccount,
            'sent'           => $sent,
            'received'       => $received,
            'pendingCount'   => $received->getCollection()->filter->isActionable()->count(),
            'activeNav'      => 'richieste-text',
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $counterparties = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($currentAccount->id)
            ->whereNotNull('company_id') // solo conti aziendali
            ->orderBy('id')
            ->get();

        return view('portal.text-requests.create', [
            'pageTitle'      => 'Nuova richiesta di pagamento',
            'currentAccount' => $currentAccount,
            'counterparties' => $counterparties,
            'activeNav'      => 'richieste-text',
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user());

        $data = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'integer', 'min:1', 'max:9999999'],
            'causale'       => ['required', 'string', 'min:3', 'max:500'],
            'note'          => ['nullable', 'string', 'max:1000'],
            'due_date'      => ['nullable', 'date', 'after:today'],
        ]);

        abort_if((int) $data['to_account_id'] === $currentAccount->id, 422, 'Non puoi inviare una richiesta a te stesso.');

        $toAccount = Account::findOrFail($data['to_account_id']);
        abort_unless($toAccount->status === 'active', 422, 'Il conto destinatario non e\' attivo.');

        $req = TextPaymentRequest::create([
            'from_account_id' => $currentAccount->id,
            'to_account_id'   => $toAccount->id,
            'amount'          => (int) $data['amount'],
            'causale'         => $data['causale'],
            'note'            => $data['note'] ?? null,
            'due_date'        => $data['due_date'] ?? null,
            'status'          => 'pending',
            'created_by'      => $currentUser->id,
        ]);

        // Notifica al debitore (tutti gli utenti del conto destinatario)
        foreach ($toAccount->managedUsers()->get() as $u) {
            $u->notify(new TextPaymentRequestSentNotification($req));
        }
        if ($toAccount->ownerUser) {
            $toAccount->ownerUser->notify(new TextPaymentRequestSentNotification($req));
        }

        return redirect()
            ->route('portal.text-requests.show', $req)
            ->with('portal_success', 'Richiesta inviata con successo.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(Request $request, TextPaymentRequest $textPaymentRequest): View|RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        // Solo le parti coinvolte possono vedere
        $isCreditor = (int) $textPaymentRequest->from_account_id === $currentAccount->id;
        $isDebtor   = (int) $textPaymentRequest->to_account_id   === $currentAccount->id;
        abort_unless($isCreditor || $isDebtor, 403);

        $textPaymentRequest->load([
            'fromAccount.company',
            'toAccount.company',
            'transfer',
            'creator',
            'actioner',
        ]);

        return view('portal.text-requests.show', [
            'pageTitle'          => 'Richiesta #' . strtoupper(substr($textPaymentRequest->uuid, 0, 8)),
            'currentAccount'     => $currentAccount,
            'req'                => $textPaymentRequest,
            'isCreditor'         => $isCreditor,
            'isDebtor'           => $isDebtor,
            'canAction'          => $isDebtor && $textPaymentRequest->isActionable(),
            'canCancel'          => $isCreditor && $textPaymentRequest->isPending(),
            'activeNav'          => 'richieste-text',
        ]);
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    public function approve(
        Request $request,
        TextPaymentRequest $textPaymentRequest,
        TransferBookingService $booking,
    ): RedirectResponse {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user());

        abort_unless($textPaymentRequest->isActionable(), 422, 'La richiesta non e\' piu\' approvabile.');
        abort_unless($textPaymentRequest->canBeActionedBy($currentAccount), 403);

        // Esegui il trasferimento
        try {
            $transfer = $booking->book([
                'initiated_by'    => $currentUser->id,
                'from_account_id' => $currentAccount->id,
                'to_account_id'   => $textPaymentRequest->from_account_id,
                'amount'          => $textPaymentRequest->amount,
                'description'     => $textPaymentRequest->causale,
                'kind'            => 'portal_text_request',
                'idempotency_key' => 'tpr_approve_' . $textPaymentRequest->uuid,
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        $textPaymentRequest->update([
            'status'      => 'approved',
            'transfer_id' => $transfer->id,
            'actioned_by' => $currentUser->id,
            'actioned_at' => now(),
        ]);

        // Notifica al creditore
        $creditorOwner = $textPaymentRequest->fromAccount?->ownerUser;
        if ($creditorOwner) {
            $creditorOwner->notify(new TextPaymentRequestApprovedNotification($textPaymentRequest));
        }

        return redirect()
            ->route('portal.text-requests.show', $textPaymentRequest)
            ->with('portal_success', 'Pagamento eseguito con successo.');
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function reject(Request $request, TextPaymentRequest $textPaymentRequest): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user());

        abort_unless($textPaymentRequest->isActionable(), 422, 'La richiesta non e\' piu\' rifiutabile.');
        abort_unless($textPaymentRequest->canBeActionedBy($currentAccount), 403);

        $note = $request->input('rejection_note', '');

        $textPaymentRequest->update([
            'status'      => 'rejected',
            'note'        => $note ?: $textPaymentRequest->note,
            'actioned_by' => $currentUser->id,
            'actioned_at' => now(),
        ]);

        $creditorOwner = $textPaymentRequest->fromAccount?->ownerUser;
        if ($creditorOwner) {
            $creditorOwner->notify(new TextPaymentRequestRejectedNotification($textPaymentRequest));
        }

        return redirect()
            ->route('portal.text-requests.index')
            ->with('portal_success', 'Richiesta rifiutata.');
    }

    // ── Cancel (da parte del creditore) ──────────────────────────────────────

    public function cancel(Request $request, TextPaymentRequest $textPaymentRequest): RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        abort_unless($textPaymentRequest->isPending(), 422, 'Solo le richieste in attesa possono essere annullate.');
        abort_unless($textPaymentRequest->canBeCancelledBy($currentAccount), 403);

        $textPaymentRequest->update(['status' => 'cancelled']);

        return redirect()
            ->route('portal.text-requests.index')
            ->with('portal_success', 'Richiesta annullata.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveCurrentContext(User $viewer): array
    {
        abort_if($viewer->canAccessBackoffice(), 403);

        // Legacy single-delegate
        if ($viewer->managed_account_id !== null) {
            $account = Account::query()
                ->with(['company', 'ownerUser'])
                ->whereKey($viewer->managed_account_id)
                ->firstOrFail();
            return [$account, $viewer];
        }

        // Multi-account switcher via session
        $sessionAccountId = session('active_account_id');
        if ($sessionAccountId) {
            $switched = Account::query()
                ->with(['company', 'ownerUser'])
                ->where('status', 'active')
                ->find($sessionAccountId);

            if ($switched && $viewer->canOperateOnAccount($switched)) {
                return [$switched, $viewer];
            }

            session()->forget('active_account_id');
        }

        // Account aziendale root
        if ($viewer->company_id !== null) {
            $account = Account::query()
                ->with(['company', 'ownerUser'])
                ->where('company_id', $viewer->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
            return [$account, $viewer];
        }

        // Fallback: account personale per utente privato
        $account = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('owner_user_id', $viewer->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();

        return [$account, $viewer];
    }
}
