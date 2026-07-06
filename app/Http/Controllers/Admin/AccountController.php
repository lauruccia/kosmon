<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    use AuthorizesBackoffice;

    public function accounts(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        return view('admin.accounts', [
            'pageTitle' => 'Conti e sottoconti',
            'accounts' => Account::query()->with(['company', 'ownerUser', 'parentAccount', 'managedUsers'])->orderByDesc('id')->get(),
            'activeNav' => 'accounts',
        ]);
    }

    public function showAccount(Request $request, Account $account): View
    {
        $this->authorizeBackoffice($request->user());

        $account->load([
            'company',
            'ownerUser',
            'parentAccount',
            'childAccounts.ownerUser',
            'childAccounts.company',
            'managedUsers.roles',
        ]);

        $recentTransfers = Transfer::query()
            ->excludeLedgerCorrections()
            ->with(['fromAccount.company', 'fromAccount.ownerUser', 'toAccount.company', 'toAccount.ownerUser', 'initiator'])
            ->where(function ($query) use ($account): void {
                $query
                    ->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->latest('booked_at')
            ->latest('id')
            ->limit(12)
            ->get();

        return view('admin.account-show', [
            'pageTitle' => 'Dettaglio conto',
            'accountRecord' => $account,
            'recentTransfers' => $recentTransfers,
            'defaultTransferLimits' => SystemSetting::userLimitDefaults()->defaultsMap(),
            'ownerEffectiveTransferLimits' => $account->ownerUser?->effectiveTransferLimits(),
            'activeNav' => 'accounts',
        ]);
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'accounts.manage');

        foreach (['max_balance', 'spending_limit', 'daily_outgoing_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'max_balance' => ['nullable', 'numeric', 'min:0'],
            'spending_limit' => ['nullable', 'numeric', 'min:0.01'],
            'daily_outgoing_limit' => ['nullable', 'numeric', 'min:0.01'],
            'allow_negative_balance' => ['required', 'boolean'],
        ]);

        $spendingLimitCents = $request->filled('spending_limit') ? ky_to_cents($validated['spending_limit']) : null;
        $dailyOutgoingCents = $request->filled('daily_outgoing_limit') ? ky_to_cents($validated['daily_outgoing_limit']) : null;

        // Coerenza: il limite per singola operazione non può superare il giornaliero.
        $this->assertLimitsAscending([
            ['field' => 'spending_limit', 'label' => 'per singola operazione', 'value' => $spendingLimitCents],
            ['field' => 'daily_outgoing_limit', 'label' => 'giornaliero', 'value' => $dailyOutgoingCents],
        ]);

        $account->forceFill([
            'status' => $validated['status'],
            'max_balance' => $request->filled('max_balance') ? ky_to_cents($validated['max_balance']) : null,
            'spending_limit' => $spendingLimitCents,
            'daily_outgoing_limit' => $dailyOutgoingCents,
            'allow_negative_balance' => (bool) $validated['allow_negative_balance'],
        ])->save();

        if ($account->type === 'subaccount') {
            $account->managedUsers()->update(['is_active' => $validated['status'] === 'active']);
        }

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.account.updated',
            'auditable_type' => Account::class,
            'auditable_id' => $account->id,
            'ip_address' => $request->ip(),
            'context' => $validated,
        ]);

        return back()->with('portal_success', 'Conto aggiornato correttamente.');
    }

    public function unlockAccount(Request $request, Account $account): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'accounts.manage');

        $account->forceFill(['locked_until' => null])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.account.unlocked',
            'auditable_type' => Account::class,
            'auditable_id'   => $account->id,
            'ip_address'     => $request->ip(),
            'context'        => ['reason' => 'admin_manual_unlock'],
        ]);

        return back()->with('portal_success', 'Conto sbloccato correttamente.');
    }
}
