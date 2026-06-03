<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Services\ScheduledPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduledPaymentController extends Controller
{
    public function __construct(
        private readonly ScheduledPaymentService $service,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $payments = ScheduledPayment::query()
            ->with(['toAccount.company', 'fromAccount.company', 'transfer'])
            ->where(fn ($q) => $q
                ->where('from_account_id', $currentAccount->id)
                ->orWhere('to_account_id', $currentAccount->id)
            )
            ->latest('scheduled_at')
            ->paginate(20);

        return view('portal.scheduled-payments.index', [
            'pageTitle'      => 'Pagamenti programmati',
            'currentAccount' => $currentAccount,
            'payments'       => $payments,
            'pendingCount'   => ScheduledPayment::where('from_account_id', $currentAccount->id)
                ->where('status', 'pending')->count(),
            'activeNav'      => 'scheduled-payments',
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
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get();

        return view('portal.scheduled-payments.create', [
            'pageTitle'      => 'Programma un pagamento',
            'currentAccount' => $currentAccount,
            'counterparties' => $counterparties,
            'activeNav'      => 'scheduled-payments',
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext($request->user());

        $isRecurring = $request->boolean('is_recurring');

        if ($isRecurring) {
            return $this->storeRecurring($request, $currentAccount, $currentUser);
        }

        $data = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount'        => ['required', 'integer', 'min:1', 'max:9999999'],
            'description'   => ['required', 'string', 'min:3', 'max:500'],
            'scheduled_at'  => ['required', 'date', 'after:now'],
        ]);

        abort_if((int) $data['to_account_id'] === $currentAccount->id, 422, 'Non puoi programmare un pagamento a te stesso.');

        $toAccount = Account::findOrFail($data['to_account_id']);
        abort_unless($toAccount->status === 'active', 422, 'Il conto destinatario non è attivo.');

        $payment = $this->service->create(
            fromAccount:  $currentAccount,
            toAccount:    $toAccount,
            amount:       (int) $data['amount'],
            description:  $data['description'],
            scheduledAt:  new \DateTime($data['scheduled_at']),
            createdBy:    $currentUser,
        );

        return redirect()
            ->route('portal.scheduled-payments.show', $payment)
            ->with('portal_success', 'Pagamento programmato per ' . \Carbon\Carbon::parse($data['scheduled_at'])->format('d/m/Y H:i') . '.');
    }

    private function storeRecurring(Request $request, Account $currentAccount, User $currentUser): RedirectResponse
    {
        $data = $request->validate([
            'to_account_id'       => ['required', 'integer', 'exists:accounts,id'],
            'amount'              => ['required', 'integer', 'min:1', 'max:9999999'],
            // max 480 per lasciare spazio al suffisso " (rata XX di XX)" aggiunto dal service
            'description'         => ['required', 'string', 'min:3', 'max:480'],
            'scheduled_at'        => ['required', 'date', 'after:now'],
            'recurrence_type'     => ['required', 'in:monthly,weekly,biweekly'],
            'recurrence_end_date' => ['required', 'date', 'after:scheduled_at'],
        ]);

        abort_if((int) $data['to_account_id'] === $currentAccount->id, 422, 'Non puoi programmare un pagamento a te stesso.');

        $toAccount = Account::findOrFail($data['to_account_id']);
        abort_unless($toAccount->status === 'active', 422, 'Il conto destinatario non è attivo.');

        $payments = $this->service->createRecurring(
            fromAccount:    $currentAccount,
            toAccount:      $toAccount,
            amount:         (int) $data['amount'],
            description:    $data['description'],
            firstDate:      new \DateTime($data['scheduled_at']),
            recurrenceType: $data['recurrence_type'],
            endDate:        \Carbon\Carbon::parse($data['recurrence_end_date'])->endOfDay(),
            createdBy:      $currentUser,
        );

        $total = count($payments);
        $label = match ($data['recurrence_type']) {
            'weekly'   => 'settimanali',
            'biweekly' => 'bisettimanali',
            default    => 'mensili',
        };

        return redirect()
            ->route('portal.scheduled-payments.index')
            ->with('portal_success', "Creati {$total} pagamenti ricorrenti {$label} a partire dal " . \Carbon\Carbon::parse($data['scheduled_at'])->format('d/m/Y') . '.');
    }

    // ── CancelGroup ───────────────────────────────────────────────────────────

    public function cancelGroup(Request $request, string $group): RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $pending = \App\Models\ScheduledPayment::where('recurrence_group', $group)
            ->where('from_account_id', $currentAccount->id)
            ->where('status', 'pending')
            ->get();

        abort_if($pending->isEmpty(), 404, 'Nessuna rata in attesa da annullare per questo gruppo.');

        foreach ($pending as $payment) {
            $payment->update(['status' => 'cancelled']);
        }

        $count = $pending->count();

        return redirect()
            ->route('portal.scheduled-payments.index')
            ->with('portal_success', "Annullate {$count} rate ricorrenti rimanenti.");
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(Request $request, ScheduledPayment $scheduledPayment): View|RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $isSender    = (int) $scheduledPayment->from_account_id === $currentAccount->id;
        $isRecipient = (int) $scheduledPayment->to_account_id   === $currentAccount->id;
        abort_unless($isSender || $isRecipient, 403);

        $scheduledPayment->load(['fromAccount.company', 'toAccount.company', 'transfer', 'creator']);

        return view('portal.scheduled-payments.show', [
            'pageTitle'        => 'Pagamento programmato #' . strtoupper(substr($scheduledPayment->uuid, 0, 8)),
            'currentAccount'   => $currentAccount,
            'payment'          => $scheduledPayment,
            'isSender'         => $isSender,
            'canCancel'        => $isSender && $scheduledPayment->isPending(),
            'activeNav'        => 'scheduled-payments',
        ]);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancel(Request $request, ScheduledPayment $scheduledPayment): RedirectResponse
    {
        [$currentAccount] = $this->resolveCurrentContext($request->user());

        $this->service->cancel($scheduledPayment, $currentAccount);

        return redirect()
            ->route('portal.scheduled-payments.index')
            ->with('portal_success', 'Pagamento programmato annullato.');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

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
