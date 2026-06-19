<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Concerns\HandlesMovementFilters;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesBackoffice;
    use HandlesMovementFilters;

    /** Default di paginazione per il listing utenti (recuperato dallo split di AdminController). */
    private const USERS_PER_PAGE = 25;

    public function users(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $search             = trim((string) $request->string('q'));
        $selectedRoleId     = $request->integer('role_id');
        $selectedStatus     = (string) $request->query('status', '');
        $selectedHolderType = (string) $request->query('account_holder_type', '');
        $selectedBalanceFilter = (string) $request->query('balance_filter', '');
        $sortField          = (string) $request->query('sort', '');
        $sortDir            = $request->query('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage            = in_array((int) $request->query('per_page'), [10, 25, 50, 100], true)
            ? (int) $request->query('per_page')
            : self::USERS_PER_PAGE;

        $balanceSub         = '(SELECT COALESCE(SUM(a.available_balance), 0) FROM accounts a WHERE a.owner_user_id = users.id)';
        $validBalanceFilters = ['negative', 'positive', 'zero', 'near_max', 'near_min', 'allow_negative'];
        $allowedSorts       = ['name', 'email', 'created_at', 'balance'];
        $usersQuery = User::query()
            ->with([
                'company.accounts',
                'managedAccount.parentAccount',
                'managedAccount.company',
                'managedAccount.ownerUser',
                'ownedAccounts.parentAccount',
                'ownedAccounts.company',
                'roles.permissions',
            ]);

        if ($search !== '') {
            $usersQuery->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhere('role', 'like', '%' . $search . '%')
                    ->orWhereHas('company', function ($companyQuery) use ($search): void {
                        $companyQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        if ($selectedRoleId > 0) {
            $usersQuery->whereHas('roles', function ($query) use ($selectedRoleId): void {
                $query->whereKey($selectedRoleId);
            });
        }

        if (in_array($selectedStatus, ['active', 'inactive'], true)) {
            $usersQuery->where('is_active', $selectedStatus === 'active');
        }

        $holderMetricsQuery = clone $usersQuery;

        if (in_array($selectedHolderType, ['company', 'private'], true)) {
            $this->applyUserHolderTypeFilter($usersQuery, $selectedHolderType);
        }

        // ── Balance filter ─────────────────────────────────────────────────────
        if (in_array($selectedBalanceFilter, $validBalanceFilters, true)) {
            match ($selectedBalanceFilter) {
                'negative'       => $usersQuery->whereRaw("{$balanceSub} < 0"),
                'positive'       => $usersQuery->whereRaw("{$balanceSub} > 0"),
                'zero'           => $usersQuery->whereRaw("{$balanceSub} = 0"),
                'near_max'       => $usersQuery->whereHas('ownedAccounts', fn ($q) =>
                                        $q->whereNotNull('max_balance')
                                          ->where('max_balance', '>', 0)
                                          ->whereRaw('available_balance >= max_balance * 0.80')),
                'near_min'       => $usersQuery->whereRaw("{$balanceSub} BETWEEN 1 AND 999"),
                'allow_negative' => $usersQuery->whereHas('ownedAccounts', fn ($q) =>
                                        $q->where('allow_negative_balance', true)),
                default          => null,
            };
        }

        $filteredUsersCount = (clone $usersQuery)->count();
        $activeUsersCount   = (clone $usersQuery)->where('is_active', true)->count();
        $superAdminCount    = (clone $usersQuery)->where('is_super_admin', true)->count();

        // ── Sort ───────────────────────────────────────────────────────────────
        if (in_array($sortField, $allowedSorts, true)) {
            if ($sortField === 'balance') {
                $usersQuery->orderByRaw("{$balanceSub} {$sortDir}");
            } else {
                $usersQuery->orderBy($sortField, $sortDir);
            }
        } else {
            $usersQuery
                ->orderByDesc('is_super_admin')
                ->orderByDesc('is_active')
                ->orderBy('name');
        }

        $users = $usersQuery
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.users', [
            'pageTitle' => 'Gestione utenti',
            'users' => $users,
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
            'companies' => Company::query()->withCount(['users', 'accounts'])->orderBy('name')->get(),
            'filteredUsersCount' => $filteredUsersCount,
            'holderTotalCount' => (clone $holderMetricsQuery)->count(),
            'activeUsersCount' => $activeUsersCount,
            'superAdminCount' => $superAdminCount,
            'companyUsersCount' => (clone $holderMetricsQuery)->where(function (Builder $query): void {
                $this->applyUserHolderTypeFilter($query, 'company');
            })->count(),
            'privateUsersCount' => (clone $holderMetricsQuery)->where(function (Builder $query): void {
                $this->applyUserHolderTypeFilter($query, 'private');
            })->count(),
            'search'                => $search,
            'selectedRoleId'        => $selectedRoleId > 0 ? $selectedRoleId : null,
            'selectedStatus'        => in_array($selectedStatus, ['active', 'inactive'], true) ? $selectedStatus : null,
            'selectedHolderType'    => in_array($selectedHolderType, ['company', 'private'], true) ? $selectedHolderType : null,
            'selectedBalanceFilter' => in_array($selectedBalanceFilter, $validBalanceFilters, true) ? $selectedBalanceFilter : '',
            'sortField'             => in_array($sortField, $allowedSorts, true) ? $sortField : '',
            'sortDir'               => $sortDir,
            'perPage'               => $perPage,
            'perPageOptions'        => [10, 25, 50, 100],
            'activeNav'             => 'users',
        ]);
    }

    public function showUser(Request $request, User $user): View
    {
        $this->authorizeBackoffice($request->user());

        $user->load([
            'company.accounts.ownerUser',
            'managedAccount.parentAccount',
            'managedAccount.company',
            'managedAccount.ownerUser',
            'ownedAccounts.parentAccount',
            'ownedAccounts.company',
            'roles.permissions',
        ]);

        $accounts = $this->accountsForUser($user);
        $accountIds = $accounts->pluck('id')->all();
        $movementFilters = $this->movementFilters($request, 'year_to_date');
        $transfersQuery = $this->movementQuery();
        $this->applyMovementDateFilters($transfersQuery, $movementFilters);

        if ($accountIds === []) {
            $transfersQuery->whereRaw('1 = 0');
        } else {
            $transfersQuery->where(function ($scope) use ($accountIds): void {
                $scope
                    ->whereIn('from_account_id', $accountIds)
                    ->orWhereIn('to_account_id', $accountIds);
            });
        }

        $incomingTotal = (clone $transfersQuery)
            ->where('status', 'booked')
            ->whereIn('to_account_id', $accountIds)
            ->sum('amount');

        $outgoingTotal = (clone $transfersQuery)
            ->where('status', 'booked')
            ->whereIn('from_account_id', $accountIds)
            ->sum('amount');

        $activeSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        $loginLogs = \App\Models\LoginLog::where('user_id', $user->id)
            ->orderByDesc('logged_in_at')
            ->limit(20)
            ->get();

        return view('admin.user-show', [
            'pageTitle' => 'Dettaglio utente',
            'userRecord' => $user,
            'accounts' => $accounts,
            'primaryAccount' => $accounts->firstWhere('type', 'primary') ?? $accounts->first(),
            'transfers' => $transfersQuery->latest('booked_at')->latest('id')->get(),
            'movementFilters' => $movementFilters,
            'movementPeriodOptions' => $this->movementPeriodOptions(),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
            'companies' => Company::query()->withCount(['users', 'accounts'])->orderBy('name')->get(),
            'defaultTransferLimits' => SystemSetting::userLimitDefaults()->defaultsMap(),
            'effectiveTransferLimits' => $user->effectiveTransferLimits(),
            'balances' => [
                'available' => $accounts->sum('available_balance'),
                'pending' => $accounts->sum('pending_balance'),
                'incoming' => $incomingTotal,
                'outgoing' => $outgoingTotal,
            ],
            'activeSessions' => $activeSessions,
            'loginLogs'      => $loginLogs,
            'activeNav' => 'users',
        ]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $isSuperAdmin = $request->boolean('is_super_admin');
        $holderType = $request->input('account_holder_type', 'company');

        $validated = $request->validate([
            'account_holder_type' => ['required', 'string', Rule::in(['private', 'company'])],
            'company_id' => [
                Rule::requiredIf(! $isSuperAdmin && $holderType === 'company'),
                'nullable',
                'integer',
                'exists:companies,id',
            ],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role_label' => ['nullable', 'string', 'max:50'],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'is_super_admin' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'company_id' => $holderType === 'company' ? ($validated['company_id'] ?? null) : null,
            'account_holder_type' => $validated['account_holder_type'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'role' => $validated['role_label'] ?? ($isSuperAdmin ? 'system-superadmin' : 'backoffice-operator'),
            'is_active' => true,
            'is_super_admin' => $isSuperAdmin,
        ]);

        if ($holderType === 'company' && ! $isSuperAdmin && isset($validated['company_id'])) {
            Account::query()
                ->where('company_id', $validated['company_id'])
                ->whereNull('parent_account_id')
                ->whereNull('owner_user_id')
                ->orderBy('id')
                ->first()?->forceFill(['owner_user_id' => $user->id])->save();
        }

        $user->roles()->sync($validated['roles'] ?? []);

        return back()->with('portal_success', 'Utente creato correttamente.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $kyLimitFields = [
            'circuit_capacity_limit', 'negative_balance_limit', 'daily_transaction_limit',
            'monthly_transaction_limit', 'per_movement_limit', 'primary_account_max_balance',
        ];
        foreach ($kyLimitFields as $field) {
            if ($request->filled($field)) {
                $request->merge([$field => str_replace(',', '.', (string) $request->input($field))]);
            }
        }

        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'email'                  => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone'                  => ['nullable', 'string', 'max:30'],
            'account_holder_type'    => ['required', 'in:company,private'],
            'company_id'             => ['nullable', 'integer', 'exists:companies,id'],
            'managed_account_id'     => ['nullable', 'integer', 'exists:accounts,id'],
            'role_label'             => ['nullable', 'string', 'max:50'],
            'is_active'              => ['required', 'boolean'],
            'is_super_admin'         => ['boolean'],
            'circuit_capacity_limit' => ['nullable', 'numeric', 'min:0'],
            'negative_balance_limit' => ['nullable', 'numeric', 'min:0'],
            'daily_transaction_limit'   => ['nullable', 'numeric', 'min:0'],
            'monthly_transaction_limit' => ['nullable', 'numeric', 'min:0'],
            'per_movement_limit'        => ['nullable', 'numeric', 'min:0'],
            'primary_account_max_balance'    => ['nullable', 'numeric', 'min:0'],
            'primary_account_allow_negative' => ['boolean'],
            'roles'   => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ]);

        $limitToCents = fn (string $field) => $request->filled($field) ? ky_to_cents($validated[$field]) : null;

        $emailChanged = $validated['email'] !== $user->email;

        $user->forceFill([
            'name'                   => $validated['name'],
            'email'                  => $validated['email'],
            'email_verified_at'      => $emailChanged ? null : $user->email_verified_at,
            'phone'                  => $validated['phone'] ?? null,
            'account_holder_type'    => $validated['account_holder_type'],
            'company_id'             => $validated['company_id'] ?? null,
            'managed_account_id'     => $validated['managed_account_id'] ?? null,
            'role'                   => $validated['role_label'] ?? $user->role,
            'is_active'              => (bool) $validated['is_active'],
            'is_super_admin'         => $request->boolean('is_super_admin'),
            'circuit_capacity_limit'    => $limitToCents('circuit_capacity_limit'),
            'negative_balance_limit'    => $limitToCents('negative_balance_limit'),
            'daily_transaction_limit'   => $limitToCents('daily_transaction_limit'),
            'monthly_transaction_limit' => $limitToCents('monthly_transaction_limit'),
            'per_movement_limit'        => $limitToCents('per_movement_limit'),
            'transfer_limits_use_defaults' => false,
        ])->save();

        $user->roles()->sync($validated['roles'] ?? []);

        $user->load(['company.accounts', 'managedAccount', 'ownedAccounts']);
        $primaryAccount = $this->accountsForUser($user)->firstWhere('type', 'primary')
            ?? $this->accountsForUser($user)->first();

        if ($primaryAccount) {
            $accountUpdates = [
                // allow_negative_balance: sempre aggiornato (il form invia sempre 0 o 1)
                'allow_negative_balance' => $request->boolean('primary_account_allow_negative'),
            ];
            if ($request->filled('primary_account_max_balance') || $request->has('primary_account_max_balance')) {
                $accountUpdates['max_balance'] = $limitToCents('primary_account_max_balance');
            }
            $primaryAccount->forceFill($accountUpdates)->save();
        }

        $message = 'Utente aggiornato correttamente.';
        if ($emailChanged) {
            $message .= ' Email modificata — la verifica è stata reimpostata.';
        }

        return back()->with('portal_success', $message);
    }

    /**
     * Verifica manualmente l'email di un utente (bypassa il link di verifica)
     * e ne garantisce l'attivazione. Utile quando l'email di verifica non arriva.
     */
    public function verifyUserEmail(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        if ($user->hasVerifiedEmail() && $user->is_active) {
            return back()->with('portal_info', 'Utente già verificato e attivo.');
        }

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_active'         => true,
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.verify_email',
            'auditable_type' => 'user',
            'auditable_id'   => $user->id,
            'context'        => ['target_user_email' => $user->email],
            'ip_address'     => $request->ip(),
        ]);

        return back()->with('portal_success', "Email di {$user->email} verificata e account attivato.");
    }

    /**
     * Verifica e attiva in blocco tutti gli utenti con email non ancora verificata.
     */
    public function verifyAllUsers(Request $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $count = 0;

        User::whereNull('email_verified_at')->each(function (User $user) use ($request, &$count) {
            $user->forceFill([
                'email_verified_at' => now(),
                'is_active'         => true,
            ])->save();

            AuditLog::create([
                'actor_user_id'  => $request->user()->id,
                'event'          => 'admin.verify_email_bulk',
                'auditable_type' => 'user',
                'auditable_id'   => $user->id,
                'context'        => ['target_user_email' => $user->email],
                'ip_address'     => $request->ip(),
            ]);

            $count++;
        });

        return back()->with('portal_success', $count === 0
            ? 'Nessun utente da verificare: erano già tutti verificati.'
            : "{$count} utenti verificati e attivati.");
    }

    public function changePasswordUser(Request $request, User $user): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'users.manage');

        $validated = $request->validate([
            'new_password'              => ['required', 'string', 'min:8', 'confirmed'],
            'new_password_confirmation' => ['required', 'string'],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['new_password']),
        ])->save();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.change_password',
            'auditable_type' => 'user',
            'auditable_id'   => $user->id,
            'context'        => ['target_user_email' => $user->email],
            'ip_address'     => $request->ip(),
        ]);

        return back()->with('portal_success', 'Password aggiornata correttamente.');
    }

    /**
     * Termina una singola sessione attiva di un utente.
     */
    public function terminateUserSession(Request $request, User $user, string $sessionId): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $deleted = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->delete();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.user.session.terminate',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['session_id' => $sessionId, 'terminated' => (bool) $deleted],
        ]);

        $msg = $deleted
            ? "Sessione terminata per {$user->name}."
            : 'Sessione non trovata o già scaduta.';

        return back()->with('admin_success', $msg);
    }

    /**
     * Termina tutte le sessioni attive di un utente.
     */
    public function terminateAllUserSessions(Request $request, User $user): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $count = DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'          => 'admin.user.sessions.terminate-all',
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'context'        => ['terminated_count' => $count],
        ]);

        return back()->with('admin_success', "Tutte le sessioni terminate per {$user->name} ({$count} sessioni).");
    }

    private function applyUserHolderTypeFilter(Builder $query, string $holderType): void
    {
        if ($holderType === 'private') {
            $query->where(function (Builder $privateQuery): void {
                $privateQuery
                    ->where('account_holder_type', 'private')
                    ->orWhereHas('ownedAccounts', fn (Builder $accountQuery) => $accountQuery->where('owner_type', 'private'))
                    ->orWhereHas('managedAccount', fn (Builder $accountQuery) => $accountQuery->where('owner_type', 'private'));
            });

            return;
        }

        $query->where(function (Builder $companyQuery): void {
            $companyQuery
                ->whereNotNull('company_id')
                ->orWhereHas('company')
                ->orWhereHas('ownedAccounts', fn (Builder $accountQuery) => $accountQuery->where('owner_type', 'company'))
                ->orWhereHas('managedAccount', fn (Builder $accountQuery) => $accountQuery->where('owner_type', 'company'));
        });
    }

    private function accountsForUser(User $user): Collection
    {
        $accounts = $user->ownedAccounts;

        if ($user->managedAccount) {
            $accounts = $accounts->prepend($user->managedAccount);
        }

        if ($accounts->isEmpty() && $user->company) {
            $accounts = $user->company->accounts;
        }

        return $accounts
            ->unique('id')
            ->sortBy([
                ['type', 'asc'],
                ['id', 'desc'],
            ])
            ->values();
    }
}
