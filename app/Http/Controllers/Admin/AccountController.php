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

    /**
     * Massimale e disponibilita' commerciale dell'intestatario, cambiati dalla
     * pagina del conto.
     *
     * Rotta a se' stante e non `admin.users.update` (bug trovato il
     * 02/09/2026): quel form mandava solo un pezzo dei campi e la validazione
     * lo bocciava ogni volta su `name`, `email` e `account_holder_type`. Da
     * questa pagina il massimale non si e' MAI salvato, e in silenzio —
     * l'errore compariva in cima alla pagina, lontano dal form.
     *
     * Non si e' rattoppato con degli hidden per due motivi. Primo, sarebbe
     * tornato a rompersi al primo campo required aggiunto al controller
     * utenti. Secondo, e' peggio del bug: `is_super_admin` e
     * `primary_account_allow_negative` non stanno in questo form, e
     * `$request->boolean()` li legge come `false` — salvare il massimale
     * avrebbe tolto il superadmin all'intestatario e spento
     * `allow_negative_balance`, cioe' proprio il flag che al massimale da'
     * senso.
     *
     * Qui si toccano due colonne e nient'altro. `transfer_limits_use_defaults`
     * resta com'e': svuotare il campo vuol dire "torna al default di sistema"
     * per chi usa i default e "nessuno scoperto" per gli altri, come nella
     * scheda utente.
     */
    public function updateOwnerLimits(Request $request, Account $account): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $owner = $account->ownerUser;

        if (! $owner) {
            return back()->withErrors([
                'negative_balance_limit' => 'Questo conto non ha un utente proprietario: collega prima l\'intestatario.',
            ]);
        }

        foreach (['negative_balance_limit', 'circuit_capacity_limit'] as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'negative_balance_limit' => ['nullable', 'numeric', 'min:0'],
            'circuit_capacity_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $precedente = [
            'negative_balance_limit' => $owner->negative_balance_limit,
            'circuit_capacity_limit' => $owner->circuit_capacity_limit,
        ];

        $owner->forceFill([
            'negative_balance_limit' => $request->filled('negative_balance_limit') ? ky_to_cents($validated['negative_balance_limit']) : null,
            'circuit_capacity_limit' => $request->filled('circuit_capacity_limit') ? ky_to_cents($validated['circuit_capacity_limit']) : null,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.account.owner_limits_updated',
            'auditable_type' => Account::class,
            'auditable_id'   => $account->id,
            'ip_address'     => $request->ip(),
            'context'        => [
                'owner_user_id' => $owner->id,
                'da'            => $precedente,
                'a'             => [
                    'negative_balance_limit' => $owner->negative_balance_limit,
                    'circuit_capacity_limit' => $owner->circuit_capacity_limit,
                ],
            ],
        ]);

        // Il massimale e' memorizzato per istanza: senza `refresh()` le righe
        // qui sotto leggerebbero il numero di prima del salvataggio.
        $account->refresh();

        return back()->with('portal_success', $this->messaggioMassimale($account));
    }

    /**
     * Il messaggio dice sempre il massimale che vale DAVVERO, non quello
     * appena digitato: `Account::massimale()` prende il piu' alto fra il fido
     * attivo in `credit_limits` e il limite dell'intestatario, e ci somma le
     * quote pagate in KY. Chi scriveva 6.000 in un conto con 15.000 di fido
     * vedeva la pagina rispondere 15.000 e pensava che il salvataggio fosse
     * andato perso.
     */
    private function messaggioMassimale(Account $account): string
    {
        $massimale    = $account->massimale();
        $limiteUtente = (int) ($account->ownerTransferLimits()['negative_balance_limit'] ?? 0);
        $fidoAttivo   = (int) ($account->activeCreditLimit()?->credit_limit ?? 0);

        $messaggio = 'Massimale e disponibilita commerciale aggiornati. Massimale effettivo del conto: '
            . ky_format($massimale) . ' ' . $account->currency_code . '.';

        if ($fidoAttivo > $limiteUtente) {
            $messaggio .= ' Vale il fido attivo di ' . ky_format($fidoAttivo) . ' ' . $account->currency_code
                . ', piu\' alto del limite dell\'intestatario: per abbassarlo davvero intervieni sul fido dell\'azienda.';
        }

        if (! $account->allow_negative_balance) {
            $messaggio .= ' Attenzione: sul conto "Saldo negativo" e\' su "Non consentito", quindi il massimale non e\' utilizzabile.';
        }

        return $messaggio;
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
