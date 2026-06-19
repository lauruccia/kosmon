<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentPlanRequest;
use App\Models\PaymentPlan;
use App\Services\PaymentPlanService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentPlanController extends Controller
{
    public function __construct(private readonly PaymentPlanService $service) {}

    /** Lista piani dell'account attivo (pagante + ricevente). */
    public function index(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $asDebtor = PaymentPlan::query()
            ->with(['toAccount.company', 'installments'])
            ->where('from_account_id', $currentAccount->id)
            ->orderByDesc('id')
            ->get();

        $asCreditor = PaymentPlan::query()
            ->with(['fromAccount.company', 'installments'])
            ->where('to_account_id', $currentAccount->id)
            ->orderByDesc('id')
            ->get();

        // Piani in attesa della mia approvazione come controparte
        $pendingApproval = PaymentPlan::query()
            ->with(['fromAccount.company', 'toAccount.company', 'installments'])
            ->where('status', 'pending_approval')
            ->where(function ($q) use ($currentAccount) {
                // Se initiator_role=debtor: la controparte e' to_account (creditore)
                // Se initiator_role=creditor: la controparte e' from_account (debitore)
                $q->where(function ($q2) use ($currentAccount) {
                    $q2->where('initiator_role', 'debtor')
                       ->where('to_account_id', $currentAccount->id);
                })->orWhere(function ($q2) use ($currentAccount) {
                    $q2->where('initiator_role', 'creditor')
                       ->where('from_account_id', $currentAccount->id);
                });
            })
            ->orderByDesc('id')
            ->get();

        return view('portal.payment-plans.index', [
            'pageTitle'       => 'Piani rateali',
            'activeNav'       => 'rate',
            'currentAccount'  => $currentAccount,
            'currentUser'     => $currentUser,
            'asDebtor'        => $asDebtor,
            'asCreditor'      => $asCreditor,
            'pendingApproval' => $pendingApproval,
        ]);
    }

    /** Form per creare un nuovo piano rateale. */
    public function create(Request $request): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        return view('portal.payment-plans.create', [
            'pageTitle'            => 'Nuovo piano rateale',
            'activeNav'            => 'rate',
            'currentAccount'       => $currentAccount,
            'currentUser'          => $currentUser,
            'counterpartyAccounts' => $this->counterpartyAccounts($currentAccount),
        ]);
    }

    /** Salva il piano rateale. */
    public function store(StorePaymentPlanRequest $request): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        $validated = $request->validated();

        $initiatorRole   = $validated['initiator_role'];
        $counterpartyId  = (int) $validated['counterparty_id'];

        // Se sono il creditore (venditore), io sono to_account e la controparte e' from_account
        // Se sono il debitore (acquirente), io sono from_account e la controparte e' to_account
        if ($initiatorRole === 'creditor') {
            $fromAccountId = $counterpartyId;
            $toAccountId   = $currentAccount->id;
        } else {
            $fromAccountId = $currentAccount->id;
            $toAccountId   = $counterpartyId;
        }

        try {
            $plan = $this->service->create(
                fromAccountId:      $fromAccountId,
                toAccountId:        $toAccountId,
                totalAmount:        ky_to_cents($validated['total_amount']),
                installmentsCount:  (int) $validated['installments_count'],
                frequency:          $validated['frequency'],
                firstDueDate:       Carbon::parse($validated['first_due_date']),
                initiatedBy:        $currentUser->id,
                description:        $validated['description'] ?? null,
                ipAddress:          $request->ip(),
                initiatorRole:      $initiatorRole,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        $roleMsg = $initiatorRole === 'creditor'
            ? 'Proposta inviata al cliente. Attendi che accetti il piano rateale.'
            : 'Proposta inviata al venditore. Attendi che accetti il piano rateale.';

        return redirect()
            ->route('portal.payment-plans.show', $plan)
            ->with('portal_success', $roleMsg);
    }

    /** Dettaglio piano + lista rate. */
    public function show(Request $request, PaymentPlan $paymentPlan): View|RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        // Only accounts involved in this plan can see it
        abort_unless(
            $paymentPlan->from_account_id === $currentAccount->id ||
            $paymentPlan->to_account_id   === $currentAccount->id,
            403
        );

        $paymentPlan->load([
            'fromAccount.company',
            'toAccount.company',
            'initiator',
            'installments.transfer',
        ]);

        return view('portal.payment-plans.show', [
            'pageTitle'      => 'Piano rateale',
            'activeNav'      => 'rate',
            'currentAccount' => $currentAccount,
            'currentUser'    => $currentUser,
            'plan'           => $paymentPlan,
            'isDebtor'       => $paymentPlan->from_account_id === $currentAccount->id,
            'isProposer'     => $paymentPlan->proposerAccount()?->id === $currentAccount->id,
            'canApprove'     => $paymentPlan->canBeApprovedBy($currentAccount),
        ]);
    }

    /** Approva una proposta di piano rateale (controparte). */
    public function approve(Request $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless($paymentPlan->from_account_id === $currentAccount->id || $paymentPlan->to_account_id === $currentAccount->id, 403);
        abort_unless($paymentPlan->canBeApprovedBy($currentAccount), 403);

        if ($paymentPlan->status !== 'pending_approval') {
            return redirect()->route('portal.payment-plans.show', $paymentPlan)
                ->with('portal_error', 'Il piano non e\' in attesa di approvazione.');
        }

        try {
            $this->service->approve($paymentPlan, $currentUser->id, $request->ip());
        } catch (\RuntimeException $e) {
            return redirect()->route('portal.payment-plans.show', $paymentPlan)
                ->with('portal_error', $e->getMessage());
        }

        $firstDate = Carbon::parse($paymentPlan->first_due_date)->format('d/m/Y');
        return redirect()
            ->route('portal.payment-plans.show', $paymentPlan)
            ->with('portal_success', 'Piano rateale accettato! La prima rata verra\' addebitata il ' . $firstDate . '.');
    }

    /** Rifiuta una proposta di piano rateale (controparte). */
    public function reject(Request $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless($paymentPlan->from_account_id === $currentAccount->id || $paymentPlan->to_account_id === $currentAccount->id, 403);
        abort_unless($paymentPlan->canBeApprovedBy($currentAccount), 403);

        if ($paymentPlan->status !== 'pending_approval') {
            return redirect()->route('portal.payment-plans.show', $paymentPlan)
                ->with('portal_error', 'Il piano non e\' in attesa di approvazione.');
        }

        try {
            $this->service->reject($paymentPlan, $currentUser->id, $request->ip());
        } catch (\RuntimeException $e) {
            return redirect()->route('portal.payment-plans.show', $paymentPlan)
                ->with('portal_error', $e->getMessage());
        }

        return redirect()
            ->route('portal.payment-plans.show', $paymentPlan)
            ->with('portal_success', 'Piano rateale rifiutato. Il proponente e\' stato notificato.');
    }

    /** Annulla piano rateale (solo il debitore o admin). */
    public function cancel(Request $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        [$currentAccount, $currentUser] = $this->resolveCurrentContext(
            $request->user(),
            $this->requestedCompanyId($request)
        );

        abort_unless($paymentPlan->from_account_id === $currentAccount->id || $request->user()->is_super_admin, 403);
        abort_unless(in_array($paymentPlan->status, ['pending_approval', 'active'], true), 422);

        $this->service->cancel($paymentPlan, $currentUser->id, $request->ip());

        return redirect()
            ->route('portal.payment-plans.index')
            ->with('portal_success', 'Piano rateale annullato. Le rate future sono state cancellate.');
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

    private function counterpartyAccounts(\App\Models\Account $currentAccount): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($currentAccount->id)
            ->orderBy('id')
            ->get();
    }
}
