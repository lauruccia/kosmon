<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountManager;
use App\Models\AuditLog;
use App\Models\SubAccountInvitation;
use App\Models\SubAccountLimitRequest;
use App\Models\Transfer;
use App\Models\User;
use App\Services\SubAccountService;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function structure(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));

        $subaccounts = $rootAccount->childAccounts()
            ->with(['managers', 'activeInvitations.invitedBy'])
            ->orderBy('id')
            ->get()
            ->map(function (Account $subaccount) {
                $latestTransfer = Transfer::query()
                    ->where(function ($query) use ($subaccount) {
                        $query->where('from_account_id', $subaccount->id)
                            ->orWhere('to_account_id', $subaccount->id);
                    })
                    ->where('status', 'booked')
                    ->latest('booked_at')
                    ->first();

                $subaccount->setRelation('latestLifecycleTransfer', $latestTransfer);

                return $subaccount;
            });

        // Pending invitations the current user received (model B)
        $pendingAssignments = AccountManager::with('account.parentAccount')
            ->where('user_id', $currentUser->id)
            ->whereNull('accepted_at')
            ->get();

        // Richieste limite/sforamento in attesa per il titolare del conto radice
        $pendingLimitRequests = collect();
        if ($currentUser->canCreateSubaccountsFor($rootAccount) && $subaccounts->isNotEmpty()) {
            $pendingLimitRequests = SubAccountLimitRequest::with(['subAccount', 'requestedBy'])
                ->whereIn('sub_account_id', $subaccounts->pluck('id'))
                ->where('status', 'pending')
                ->latest()
                ->get();
        }

        // Richieste del sottoconto attivo (per il gestore che sta operando su un sub)
        $mySubAccountRequests = collect();
        if ($currentAccount->isSubAccount()) {
            $mySubAccountRequests = SubAccountLimitRequest::where('sub_account_id', $currentAccount->id)
                ->latest()
                ->limit(10)
                ->get();
        }

        return view('portal.accounts', [
            'pageTitle'              => 'Struttura conti',
            'currentAccount'         => $currentAccount,
            'currentUser'            => $currentUser,
            'rootAccount'            => $rootAccount,
            'subaccounts'            => $subaccounts,
            'canManageSubaccounts'   => $currentUser->canCreateSubaccountsFor($rootAccount),
            'pendingAssignments'     => $pendingAssignments,
            'pendingLimitRequests'   => $pendingLimitRequests,
            'mySubAccountRequests'   => $mySubAccountRequests,
            'switchableAccounts'     => $currentUser->switchableAccounts(),
            'activeAccountId'        => session('active_account_id'),
            'activeNav'              => 'conti',
        ]);
    }

    public function storeSubaccount(Request $request, SubAccountService $service): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canCreateSubaccountsFor($rootAccount), 403);

        foreach (['spending_limit', 'daily_outgoing_limit', 'monthly_outgoing_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'account_name'          => ['required', 'string', 'max:120'],
            'manager_email'         => ['nullable', 'email', 'max:120'],
            'spending_limit'        => ['nullable', 'numeric', 'min:0.01'],
            'daily_outgoing_limit'  => ['nullable', 'numeric', 'min:0.01'],
            'monthly_outgoing_limit' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        foreach (['spending_limit', 'daily_outgoing_limit', 'monthly_outgoing_limit'] as $field) {
            $validated[$field] = $request->filled($field) ? ky_to_cents($validated[$field]) : null;
        }

        // Coerenza: per-operazione ≤ giornaliero ≤ mensile.
        $this->assertLimitsAscending([
            ['field' => 'spending_limit', 'label' => 'per singola operazione', 'value' => $validated['spending_limit']],
            ['field' => 'daily_outgoing_limit', 'label' => 'giornaliero', 'value' => $validated['daily_outgoing_limit']],
            ['field' => 'monthly_outgoing_limit', 'label' => 'mensile', 'value' => $validated['monthly_outgoing_limit']],
        ]);

        try {
            $service->create(
                rootAccount: $rootAccount,
                createdBy: $currentUser,
                attributes: $validated,
                managerEmail: $validated['manager_email'] ?? null,
                ipAddress: $request->ip(),
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Sottoconto creato correttamente.');
    }

    public function inviteManager(Request $request, Account $subaccount, SubAccountService $service): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        $subaccount = $this->resolveManagedSubaccount($currentUser, $rootAccount, $subaccount);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:120'],
        ]);

        try {
            $service->inviteManager($subaccount, $currentUser, $validated['email'], $request->ip());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        $existing = User::where('email', $validated['email'])->exists();
        $msg = $existing
            ? 'Notifica di accesso inviata all\'utente registrato.'
            : 'Invito email inviato. Il destinatario ha 7 giorni per registrarsi.';

        return back()->with('portal_success', $msg);
    }

    public function cancelInvitation(Request $request, Account $subaccount, SubAccountInvitation $invitation): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        abort_unless($currentUser->canCreateSubaccountsFor($rootAccount), 403);
        abort_unless($invitation->account_id === $subaccount->id, 404);

        $invitation->delete();

        return back()->with('portal_success', 'Invito annullato.');
    }

    public function revokeManager(Request $request, Account $subaccount, User $manager, SubAccountService $service): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        $subaccount = $this->resolveManagedSubaccount($currentUser, $rootAccount, $subaccount);

        try {
            $service->revokeManager($subaccount, $manager, $currentUser, $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('portal_error', $e->getMessage());
        }

        return back()->with('portal_success', 'Accesso revocato correttamente.');
    }

    public function acceptAssignment(Request $request, Account $subaccount, SubAccountService $service): RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);

        try {
            $service->acceptByExistingUser($subaccount, $request->user());
        } catch (\RuntimeException $e) {
            return redirect()->route('portal.accounts.structure')->with('portal_error', $e->getMessage());
        }

        return redirect()->route('portal.accounts.structure')
            ->with('portal_success', 'Accesso al sottoconto accettato. Puoi ora selezionarlo dal menu.');
    }

    public function declineAssignment(Request $request, Account $subaccount): RedirectResponse
    {
        abort_if($request->user()->canAccessBackoffice(), 403);

        AccountManager::where('account_id', $subaccount->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('accepted_at')
            ->delete();

        return redirect()->route('portal.accounts.structure')
            ->with('portal_success', 'Invito rifiutato.');
    }

    public function updateSubaccountLimits(Request $request, Account $subaccount, SubAccountService $service): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        $subaccount = $this->resolveManagedSubaccount($currentUser, $rootAccount, $subaccount);

        foreach (['spending_limit', 'daily_outgoing_limit', 'monthly_outgoing_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'spending_limit'         => ['nullable', 'numeric', 'min:0.01'],
            'daily_outgoing_limit'   => ['nullable', 'numeric', 'min:0.01'],
            'monthly_outgoing_limit' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        foreach (['spending_limit', 'daily_outgoing_limit', 'monthly_outgoing_limit'] as $field) {
            $validated[$field] = $request->filled($field) ? ky_to_cents($validated[$field]) : null;
        }

        // Coerenza: per-operazione ≤ giornaliero ≤ mensile.
        $this->assertLimitsAscending([
            ['field' => 'spending_limit', 'label' => 'per singola operazione', 'value' => $validated['spending_limit']],
            ['field' => 'daily_outgoing_limit', 'label' => 'giornaliero', 'value' => $validated['daily_outgoing_limit']],
            ['field' => 'monthly_outgoing_limit', 'label' => 'mensile', 'value' => $validated['monthly_outgoing_limit']],
        ]);

        $service->updateLimits($subaccount, $validated, $currentUser, $request->ip());

        return back()->with('portal_success', 'Limiti aggiornati.');
    }

    public function updateSubaccountStatus(Request $request, Account $subaccount, SubAccountService $service): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        $subaccount = $this->resolveManagedSubaccount($currentUser, $rootAccount, $subaccount);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        $service->setStatus($subaccount, $validated['status'], $currentUser, $request->ip());

        $message = $validated['status'] === 'active'
            ? 'Sottoconto riattivato.'
            : 'Sottoconto sospeso.';

        return back()->with('portal_success', $message);
    }

    // ─── Legacy top-up (kept for backward compat) ─────────────────────────

    public function topUpSubaccount(Request $request, Account $subaccount, TransferBookingService $bookingService): RedirectResponse
    {
        if ($redirect = $this->redirectBackofficeUser($request->user())) {
            return $redirect;
        }

        [$currentAccount, $currentUser, $rootAccount] = $this->resolveContext($request->user(), $this->requestedCompanyId($request));
        $subaccount = $this->resolveManagedSubaccount($currentUser, $rootAccount, $subaccount);

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        abort_if($subaccount->status !== 'active', 422, 'Il sottoconto deve essere attivo.');

        try {
            $bookingService->book([
                'initiated_by'    => $currentUser->id,
                'from_account_id' => $rootAccount->id,
                'to_account_id'   => $subaccount->id,
                'amount'          => ky_to_cents($validated['amount']),
                'description'     => $validated['description'] ?? 'Ricarica budget sottoconto',
                'kind'            => 'subaccount_funding',
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->with('portal_error', $exception->getMessage());
        }

        return back()->with('portal_success', 'Budget ricaricato correttamente sul sottoconto.');
    }

    // ─── Private helpers ───────────────────────────────────────────────────

    private function resolveManagedSubaccount(User $currentUser, Account $rootAccount, Account $subaccount): Account
    {
        abort_unless($currentUser->canCreateSubaccountsFor($rootAccount), 403);
        abort_unless($subaccount->parent_account_id === $rootAccount->id, 404);

        return $subaccount->loadMissing(['managers', 'parentAccount', 'activeInvitations']);
    }

    private function resolveContext(User $viewer, ?int $requestedCompanyId = null): array
    {
        abort_if($viewer->canAccessBackoffice(), 403);

        if ($viewer->managed_account_id !== null) {
            $currentAccount = Account::query()->with(['company', 'ownerUser', 'parentAccount'])->findOrFail($viewer->managed_account_id);
            $rootAccount = $currentAccount->parentAccount ?? $currentAccount;
            return [$currentAccount, $viewer, $rootAccount];
        }

        if ($viewer->company_id !== null) {
            $rootAccount = Account::query()
                ->with(['company', 'ownerUser', 'parentAccount'])
                ->where('company_id', $viewer->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->orderBy('id')
                ->firstOrFail();
            return [$rootAccount, $viewer, $rootAccount];
        }

        $rootAccount = Account::query()
            ->with(['company', 'ownerUser', 'parentAccount'])
            ->where('owner_user_id', $viewer->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->firstOrFail();

        return [$rootAccount, $viewer, $rootAccount];
    }

    private function requestedCompanyId(Request $request): ?int
    {
        return $request->filled('company_id') ? $request->integer('company_id') : null;
    }

    private function redirectBackofficeUser(User $viewer): ?RedirectResponse
    {
        return $viewer->canAccessBackoffice() ? redirect()->route('admin.dashboard') : null;
    }
}
