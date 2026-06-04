<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Services\TransferBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BrokerController extends Controller
{
    /**
     * Dashboard broker: lista clienti assegnati con saldo e movimenti recenti.
     */
    public function dashboard(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasRole('broker') && ! $user->canAccessBackoffice()) {
            abort(403, 'Accesso riservato agli operatori broker.');
        }

        // Admin vede tutto, broker vede solo i propri clienti
        $query = Company::query()
            ->with(['accounts' => fn ($q) => $q->whereNull('parent_account_id')->where('status', 'active'), 'broker'])
            ->where('status', 'active');

        if (! $user->canAccessBackoffice()) {
            $query->where('broker_user_id', $user->id);
        }

        $companies = $query->orderBy('name')->get();

        // Raccoglie tutti gli account IDs in un colpo solo per evitare N+1
        $accountIds = $companies->flatMap(fn ($c) => $c->accounts->pluck('id'))->filter()->unique()->values();

        // Una singola query per il trasferimento più recente per ogni account
        $recentTransfers = Transfer::query()
            ->whereIn('from_account_id', $accountIds)
            ->orWhereIn('to_account_id', $accountIds)
            ->where('status', 'booked')
            ->latest('booked_at')
            ->get()
            ->groupBy(function (Transfer $t) {
                // Indicizza per entrambi gli account così la lookup è O(1)
                return [$t->from_account_id, $t->to_account_id];
            });

        // Mappa più semplice: per ogni account prende il primo transfer nel gruppo
        $latestByAccount = collect();
        foreach ($recentTransfers->flatten(1) as $transfer) {
            foreach ([$transfer->from_account_id, $transfer->to_account_id] as $aid) {
                if (! $latestByAccount->has($aid)) {
                    $latestByAccount->put($aid, $transfer);
                }
            }
        }

        $clients = $companies->map(function (Company $company) use ($latestByAccount) {
            $account = $company->accounts->first();
            return [
                'company'        => $company,
                'account'        => $account,
                'recentTransfer' => $account ? $latestByAccount->get($account->id) : null,
            ];
        });

        return view('broker.dashboard', [
            'pageTitle' => 'Dashboard Operatore',
            'clients'   => $clients,
            'isSuperAdmin' => $user->canAccessBackoffice(),
        ]);
    }

    /**
     * Scheda cliente: saldo, movimenti recenti e azioni rapide.
     */
    public function showClient(Request $request, Company $company): View|RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user, $company);

        $account = Account::query()
            ->with(['creditLimits', 'ownerUser'])
            ->where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->firstOrFail();

        $transfers = Transfer::query()
            ->with(['fromAccount.company', 'toAccount.company'])
            ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->where('status', 'booked')
            ->latest('booked_at')
            ->take(20)
            ->get();

        return view('broker.client-show', [
            'pageTitle' => $company->name,
            'company'   => $company,
            'account'   => $account,
            'transfers' => $transfers,
        ]);
    }

    /**
     * Form pagamento per conto del cliente (broker avvia pagamento da conto cliente).
     */
    public function payForm(Request $request, Company $company): View|RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user, $company);

        $fromAccount = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->firstOrFail();

        $counterpartyAccounts = Account::query()
            ->with(['company', 'ownerUser'])
            ->where('status', 'active')
            ->whereKeyNot($fromAccount->id)
            ->whereNull('parent_account_id')
            ->orderBy('id')
            ->get();

        return view('broker.pay', [
            'pageTitle'            => 'Paga per conto di ' . $company->name,
            'company'              => $company,
            'fromAccount'          => $fromAccount,
            'counterpartyAccounts' => $counterpartyAccounts,
        ]);
    }

    /**
     * Invia il pagamento per conto del cliente.
     */
    public function paySubmit(Request $request, Company $company, TransferBookingService $bookingService): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeAccess($user, $company);

        $fromAccount = Account::query()
            ->where('company_id', $company->id)
            ->whereNull('parent_account_id')
            ->where('status', 'active')
            ->firstOrFail();

        $request->merge(['amount' => str_replace(',', '.', (string) $request->input('amount'))]);

        $validated = $request->validate([
            'to_account_id' => ['required', 'integer', 'exists:accounts,id', 'different:' . $fromAccount->id],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'description'   => ['nullable', 'string', 'max:500'],
        ]);

        $amountCents = ky_to_cents($validated['amount']);

        try {
            $bookingService->book([
                'initiated_by'    => $user->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => (int) $validated['to_account_id'],
                'amount'          => $amountCents,
                'description'     => $validated['description'] ?? 'Pagamento operato da broker ' . $user->name,
                'kind'            => 'broker_payment',
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $request->ip(),
            ]);
        } catch (\RuntimeException $e) {
            $bookingService->recordRejectedAttempt([
                'initiated_by'    => $user->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id'   => (int) $validated['to_account_id'],
                'amount'          => $amountCents,
                'idempotency_key' => (string) Str::uuid(),
                'ip_address'      => $request->ip(),
            ], $e->getMessage());

            return back()->withInput()->with('portal_error', $e->getMessage());
        }

        return redirect()->route('broker.clients.show', $company)
            ->with('portal_success', 'Pagamento di ' . ky_format($amountCents) . ' KY eseguito per conto di ' . $company->name . '.');
    }

    // ──────────────────────────────────────────────────────────────────────

    private function authorizeAccess(\App\Models\User $user, Company $company): void
    {
        if ($user->canAccessBackoffice()) {
            return;
        }

        if (! $user->hasRole('broker')) {
            abort(403, 'Accesso riservato agli operatori broker.');
        }

        if ((int) $company->broker_user_id !== $user->id) {
            abort(403, 'Non sei il broker assegnato a questa azienda.');
        }
    }
}
