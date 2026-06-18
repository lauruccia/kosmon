<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\NettingProposal;
use App\Models\PaymentPlan;
use App\Models\ScheduledPayment;
use App\Models\TextPaymentRequest;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Listing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\StreamedResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    private const REFUND_WINDOW_DAYS = 30;
    private const USERS_PER_PAGE = 25;

    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public function dashboard(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $movementFilters = $this->movementFilters($request);
        $dashboardTransfersQuery = $this->movementQuery();
        $this->applyMovementDateFilters($dashboardTransfersQuery, $movementFilters);

        return view('admin.dashboard', [
            'pageTitle'               => 'Superadmin KMoney',
            'stats'                   => $this->stats(),
            'circuitKpis'             => $this->circuitKpis(),
            'monthlyChart'            => $this->monthlyChartData(),
            'topCompanies'            => $this->topCompaniesByVolume(),
            'refundWindowDays'        => self::REFUND_WINDOW_DAYS,
            'dashboardTransfers'      => $dashboardTransfersQuery->latest('booked_at')->latest('id')->take(30)->get(),
            'dashboardMovementTotals' => $this->movementTotals(clone $dashboardTransfersQuery),
            'movementFilters'         => $movementFilters,
            'movementPeriodOptions'   => $this->movementPeriodOptions(),
            'activeNav'               => 'admin',
        ]);
    }

    public function clearCache(Request $request): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        \Illuminate\Support\Facades\Artisan::call('config:clear');
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        \Illuminate\Support\Facades\Artisan::call('view:clear');

        return back()->with('success', 'Cache svuotata con successo.');
    }

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

    public function companies(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->companyDirectoryFilters($request);
        [$companies, $stats, $sectorOptions] = $this->buildAdminCompanyList($filters);

        return view('admin.companies', [
            'pageTitle'    => 'Aziende del circuito',
            'companies'    => $companies,
            'stats'        => $stats,
            'filters'      => $filters,
            'sectorOptions'=> $sectorOptions,
            'activeNav'    => 'companies',
        ]);
    }

    public function showCompany(Request $request, Company $company): View
    {
        $this->authorizeBackoffice($request->user());

        $company->load(['broker', 'accounts.creditLimits', 'users', 'kycDocuments']);

        $brokerUsers = User::query()
            ->where(function ($q) {
                $q->where('role', 'broker')
                  ->orWhere('is_super_admin', true);
            })
            ->orderBy('name')
            ->get();

        $account = $company->accounts->whereNull('parent_account_id')->where('status', 'active')->first();

        $recentTransfers = $account
            ? Transfer::query()
                ->with(['fromAccount.company', 'toAccount.company', 'initiator'])
                ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
                ->where('status', 'booked')
                ->latest('booked_at')
                ->take(20)
                ->get()
            : collect();

        return view('admin.company-show', [
            'pageTitle'       => $company->name,
            'company'         => $company,
            'account'         => $account,
            'brokerUsers'     => $brokerUsers,
            'recentTransfers' => $recentTransfers,
            'activeNav'       => 'companies',
        ]);
    }

    public function assignBroker(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'broker_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $company->update(['broker_user_id' => $validated['broker_user_id'] ?: null]);

        return back()->with('portal_success',
            $validated['broker_user_id']
                ? 'Broker assegnato correttamente a ' . $company->name . '.'
                : 'Broker rimosso da ' . $company->name . '.'
        );
    }

    public function transfers(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $movementFilters = $this->movementFilters($request, 'current_quarter');
        $transfersQuery = $this->movementQuery();
        $this->applyMovementDateFilters($transfersQuery, $movementFilters);

        // Ricerca per nome utente (mittente o destinatario)
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $transfersQuery->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($search): void {
                $like = '%' . $search . '%';
                // display_name è un accessor: la colonna reale è account_name,
                // con fallback su users.name (privati) e companies.name (aziende)
                $q->whereHas('fromAccount', function ($q2) use ($like): void {
                    $q2->where('account_name', 'like', $like)
                        ->orWhereHas('ownerUser', fn ($u) => $u->where('name', 'like', $like))
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
                })->orWhereHas('toAccount', function ($q2) use ($like): void {
                    $q2->where('account_name', 'like', $like)
                        ->orWhereHas('ownerUser', fn ($u) => $u->where('name', 'like', $like))
                        ->orWhereHas('company', fn ($c) => $c->where('name', 'like', $like));
                });
            });
        }

        return view('admin.transfers', [
            'pageTitle' => 'Movimenti e correzioni',
            'refundWindowDays' => self::REFUND_WINDOW_DAYS,
            'transfers' => $transfersQuery->latest('booked_at')->latest('id')->get(),
            'movementTotals' => $this->movementTotals(clone $transfersQuery),
            'movementFilters' => $movementFilters,
            'movementPeriodOptions' => $this->movementPeriodOptions(),
            'supportsTransferRefunds' => $this->supportsTransferRefunds(),
            'activeNav' => 'transfers',
            'search' => $search,
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

    public function refundTransfer(Request $request, Transfer $transfer, TransferBookingService $bookingService): RedirectResponse
    {
        abort_unless($this->supportsTransferRefunds(), 409, 'Aggiorna il database per abilitare refund e storni amministrativi.');
        $this->authorizePermission($request->user(), 'movements.manage');

        abort_unless($transfer->status === 'booked', 422, 'Solo i movimenti contabilizzati possono essere stornati.');
        abort_unless($transfer->reversalChildren()->count() === 0, 422, 'Questo movimento e gia stato stornato.');
        abort_unless(
            $transfer->booked_at !== null && $transfer->booked_at->greaterThanOrEqualTo(CarbonImmutable::now()->subDays(self::REFUND_WINDOW_DAYS)),
            422,
            'La finestra di storno di ' . self::REFUND_WINDOW_DAYS . ' giorni e scaduta.'
        );

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $refund = $bookingService->book([
            'initiated_by' => $request->user()->id,
            'from_account_id' => $transfer->to_account_id,
            'to_account_id' => $transfer->from_account_id,
            'amount' => $transfer->amount,
            'description' => $validated['reason'] ?: 'Storno amministrativo di ' . $transfer->reference,
            'kind' => 'admin_refund',
            'idempotency_key' => (string) Str::uuid(),
            'ip_address' => $request->ip(),
        ]);

        $refund->forceFill([
            'reversed_transfer_id' => $transfer->id,
            'refunded_at' => CarbonImmutable::now(),
            'admin_action' => 'refund',
        ])->save();

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'admin.transfer.refunded',
            'auditable_type' => Transfer::class,
            'auditable_id' => $refund->id,
            'ip_address' => $request->ip(),
            'context' => [
                'original_transfer_id' => $transfer->id,
                'refund_transfer_id' => $refund->id,
                'reason' => $validated['reason'] ?? null,
            ],
        ]);

        return back()->with('portal_success', 'Movimento stornato correttamente.');
    }

    private function stats(): array
    {
        return [
            'roles'        => Role::query()->count(),
            'permissions'  => Permission::query()->count(),
            'users'        => User::query()->count(),
            'superAdmins'  => User::query()->where('is_super_admin', true)->count(),
            'companies'    => Company::query()->count(),
            'accounts'     => Account::query()->count(),
            'transfers'    => Transfer::query()->count(),
            'companyUsers' => User::query()->where('account_holder_type', 'company')->count(),
            'privateUsers' => User::query()->where('account_holder_type', 'private')->count(),
        ];
    }

    /**
     * KPI specifici del circuito: liquidità, volumi, shop, annunci.
     */
    private function circuitKpis(): array
    {
        $now = CarbonImmutable::now();
        $startOfMonth  = $now->startOfMonth();
        $startOf30Days = $now->subDays(30)->startOfDay();

        // KY totali in circolazione (somma saldi positivi su conti primari attivi)
        $kyInCirculation = Account::query()
            ->where('status', 'active')
            ->sum('available_balance');

        // Volume movimentato nel mese corrente (solo booked)
        $volumeThisMonth = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $startOfMonth)
            ->sum('amount');

        // Volume mese precedente (per calcolo variazione %)
        $startPrevMonth = $now->startOfMonth()->subMonth();
        $endPrevMonth   = $now->startOfMonth()->subSecond();
        $volumePrevMonth = Transfer::query()
            ->where('status', 'booked')
            ->whereBetween('booked_at', [$startPrevMonth, $endPrevMonth])
            ->sum('amount');

        $volumeChange = $volumePrevMonth > 0
            ? round((($volumeThisMonth - $volumePrevMonth) / $volumePrevMonth) * 100, 1)
            : null;

        // Nuovi utenti ultimi 30 giorni
        $newUsers30d = User::query()
            ->where('created_at', '>=', $startOf30Days)
            ->count();

        // Movimenti oggi
        $transfersToday = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $now->startOfDay())
            ->count();

        // Shop e annunci attivi
        $activeListings      = Listing::query()->active()->count();
        $activeAnnouncements = Announcement::query()->active()->count();

        // Movimento medio per transazione (mese corrente)
        $avgMovement = Transfer::query()
            ->where('status', 'booked')
            ->where('booked_at', '>=', $startOfMonth)
            ->avg('amount');

        return [
            'kyInCirculation'    => (int) $kyInCirculation,
            'volumeThisMonth'    => (int) $volumeThisMonth,
            'volumeChange'       => $volumeChange,      // null se nessun dato prev
            'newUsers30d'        => $newUsers30d,
            'transfersToday'     => $transfersToday,
            'activeListings'     => $activeListings,
            'activeAnnouncements'=> $activeAnnouncements,
            'avgMovement'        => $avgMovement ? (int) round($avgMovement) : 0,
        ];
    }

    /**
     * Serie mensile degli ultimi 6 mesi per il grafico linee.
     * Ritorna ['labels' => [...], 'volumes' => [...], 'counts' => [...]].
     */
    private function monthlyChartData(): array
    {
        $months = collect();
        $now = CarbonImmutable::now();

        for ($i = 5; $i >= 0; $i--) {
            $months->push($now->subMonths($i)->startOfMonth());
        }

        $periodExpression = DB::getDriverName() === 'mysql'
            ? "DATE_FORMAT(booked_at, '%Y-%m')"
            : "strftime('%Y-%m', booked_at)";

        // Recupera i dati dal DB con un'unica query
        $rows = Transfer::query()
            ->select(
                DB::raw("{$periodExpression} as ym"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('COUNT(*) as total_count'),
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $months->first())
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $labels  = [];
        $volumes = [];
        $counts  = [];

        foreach ($months as $month) {
            $ym  = $month->format('Y-m');
            $row = $rows->get($ym);

            $labels[]  = $month->locale('it')->isoFormat('MMM YY');
            $volumes[] = $row ? (int) $row->total_volume : 0;
            $counts[]  = $row ? (int) $row->total_count  : 0;
        }

        return compact('labels', 'volumes', 'counts');
    }

    /**
     * Top 5 aziende per volume movimentato (ultimi 90 giorni).
     */
    private function topCompaniesByVolume(): \Illuminate\Support\Collection
    {
        $since = CarbonImmutable::now()->subDays(90);

        // Raggruppa per azienda mittente
        $outgoing = Transfer::query()
            ->join('accounts', 'transfers.from_account_id', '=', 'accounts.id')
            ->join('companies', 'accounts.company_id', '=', 'companies.id')
            ->select('companies.name', DB::raw('SUM(transfers.amount) as volume'))
            ->where('transfers.status', 'booked')
            ->where('transfers.booked_at', '>=', $since)
            ->whereNotNull('accounts.company_id')
            ->groupBy('companies.name')
            ->orderByDesc('volume')
            ->limit(5)
            ->get();

        return $outgoing;
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

    private function movementQuery(): Builder
    {
        $relations = [
            'fromAccount.company',
            'fromAccount.ownerUser',
            'toAccount.company',
            'toAccount.ownerUser',
            'initiator',
        ];

        if ($this->supportsTransferRefunds()) {
            $relations[] = 'reversedTransfer';
            $relations[] = 'reversalChildren';
        }

        return Transfer::query()->with($relations);
    }

    private function movementFilters(Request $request, string $defaultPeriod = 'current_quarter'): array
    {
        $period = (string) $request->query('period', $defaultPeriod);
        $periods = array_keys($this->movementPeriodOptions());

        if (! in_array($period, $periods, true)) {
            $period = $defaultPeriod;
        }

        $now = CarbonImmutable::now();
        $from = null;
        $to = null;

        if ($period === 'custom') {
            $fromInput = trim((string) $request->query('from_date', ''));
            $toInput = trim((string) $request->query('to_date', ''));
            $from = $this->parseFilterDate($fromInput, false);
            $to = $this->parseFilterDate($toInput, true);
        } else {
            [$from, $to] = match ($period) {
                'today' => [$now->startOfDay(), $now->endOfDay()],
                'current_month' => [$now->startOfMonth(), $now->endOfMonth()],
                'current_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
                'year_to_date' => [$now->startOfYear(), $now->endOfDay()],
                'previous_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
                default => [null, null],
            };
        }

        if ($from && $to && $from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [
            'period' => $period,
            'from_date' => $from?->format('Y-m-d') ?? trim((string) $request->query('from_date', '')),
            'to_date' => $to?->format('Y-m-d') ?? trim((string) $request->query('to_date', '')),
            'from' => $from,
            'to' => $to,
            'label' => $this->movementPeriodOptions()[$period] ?? 'Periodo personalizzato',
        ];
    }

    private function applyMovementDateFilters(Builder $query, array $filters): void
    {
        if ($filters['from'] instanceof CarbonImmutable) {
            $query->where('booked_at', '>=', $filters['from']);
        }

        if ($filters['to'] instanceof CarbonImmutable) {
            $query->where('booked_at', '<=', $filters['to']);
        }
    }

    private function movementTotals(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'bookedCount' => (clone $query)->where('status', 'booked')->count(),
            'volume' => (clone $query)->where('status', 'booked')->sum('amount'),
            'refunds' => $this->supportsTransferRefunds()
                ? (clone $query)->whereNotNull('admin_action')->count()
                : 0,
        ];
    }

    private function movementPeriodOptions(): array
    {
        return [
            'all' => 'Tutti i movimenti',
            'today' => 'Oggi',
            'current_month' => 'Mese corrente',
            'current_quarter' => 'Trimestre corrente',
            'year_to_date' => 'Anno in corso',
            'previous_year' => 'Anno precedente',
            'custom' => 'Intervallo personalizzato',
        ];
    }

    private function parseFilterDate(string $value, bool $endOfDay): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function supportsTransferRefunds(): bool
    {
        return Schema::hasColumns('transfers', ['reversed_transfer_id', 'refunded_at', 'admin_action']);
    }

    private function companyDirectoryFilters(Request $request): array
    {
        $status = trim((string) $request->query('status', ''));
        $kycStatus = trim((string) $request->query('kyc_status', ''));
        $plan = trim((string) $request->query('plan', ''));

        return [
            'q'          => trim((string) $request->query('q', '')),
            'sector'     => trim((string) $request->query('sector', '')),
            'status'     => in_array($status, ['active', 'pending', 'suspended'], true) ? $status : '',
            'kyc_status' => in_array($kycStatus, ['approved', 'pending', 'under_review', 'rejected'], true) ? $kycStatus : '',
            'plan'       => in_array($plan, ['ecommerce', 'vetrina', 'biglietto', 'anagrafica'], true) ? $plan : '',
        ];
    }

    private function buildAdminCompanyList(array $filters): array
    {
        $sectorOptions = Company::query()
            ->selectRaw('sector')
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector');

        $companies = Company::query()
            ->withCount(['users', 'listings', 'announcements'])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $s = $filters['q'];
                $query->where(fn ($q) =>
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%")
                      ->orWhere('vat_number', 'like', "%{$s}%")
                      ->orWhere('sector', 'like', "%{$s}%")
                );
            })
            ->when($filters['sector'] !== '', fn ($q) => $q->where('sector', $filters['sector']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['kyc_status'] !== '', fn ($q) => $q->where('kyc_status', $filters['kyc_status']))
            ->when($filters['plan'] !== '', fn ($q) => $q->where('subscription_plan', $filters['plan']))
            ->orderByRaw("CASE
                WHEN subscription_plan = 'ecommerce'  THEN 0
                WHEN subscription_plan = 'vetrina'    THEN 1
                WHEN subscription_plan = 'biglietto'  THEN 2
                WHEN subscription_plan = 'anagrafica' THEN 3
                ELSE 4 END")
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(80)
            ->withQueryString();

        $stats = [
            'total'    => Company::count(),
            'active'   => Company::where('status', 'active')->count(),
            'verified' => Company::where('kyc_status', 'approved')->count(),
            'plans'    => Company::whereNotNull('subscription_plan')->count(),
        ];

        return [$companies, $stats, $sectorOptions];
    }


    // ─── Report avanzato circuito ────────────────────────────────────────────

    public function report(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->movementFilters($request, 'current_month');
        $totals  = $this->movementTotals(
            $this->applyMovementDateFiltersReturn($this->movementQuery(), $filters)
        );

        return view('admin.report', [
            'pageTitle'           => 'Rapporti circuito',
            'filters'             => $filters,
            'periodOptions'       => $this->movementPeriodOptions(),
            'totals'              => $totals,
            'chartData'           => $this->reportChartData($filters),
            'topCompanies'        => $this->topCompaniesByVolumeForPeriod($filters['from'], $filters['to'], 10),
            'activeNav'           => 'report',
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeBackoffice($request->user());

        $filters = $this->movementFilters($request, 'all');
        $query   = $this->applyMovementDateFiltersReturn($this->movementQuery(), $filters);

        $filename = 'movimenti-kmoney-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 per Excel
            fwrite($out, "ï»¿");

            fputcsv($out, [
                'ID', 'Data', 'Stato', 'Tipo',
                'Da (azienda)', 'Da (conto)',
                'A (azienda)', 'A (conto)',
                'Importo KY', 'Causale', 'Idempotency key',
            ], ';');

            $query->orderBy('booked_at')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->id,
                        $t->booked_at?->format('d/m/Y H:i') ?? '-',
                        $t->status,
                        $t->kind ?? '-',
                        $t->fromAccount?->company?->name ?? $t->fromAccount?->display_name ?? '-',
                        $t->fromAccount?->account_number ?? '-',
                        $t->toAccount?->company?->name ?? $t->toAccount?->display_name ?? '-',
                        $t->toAccount?->account_number ?? '-',
                        ky_format($t->amount),
                        $t->description ?? '-',
                        $t->idempotency_key ?? '-',
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function applyMovementDateFiltersReturn(Builder $query, array $filters): Builder
    {
        $this->applyMovementDateFilters($query, $filters);
        return $query;
    }

    /**
     * Dati serie temporale per il grafico nel report.
     * Granularita' automatica: giornaliera (<=14gg), settimanale (<=90gg), mensile (resto).
     */
    private function reportChartData(array $filters): array
    {
        $from = $filters['from'];
        $to   = $filters['to'];

        if (! $from || ! $to) {
            // Nessun filtro: ultimi 6 mesi mensili
            return $this->monthlyChartData();
        }

        $days = (int) $from->diffInDays($to);

        if ($days <= 14) {
            $granularity = 'day';
            $fmt = '%Y-%m-%d';
            $mysqlFmt = '%Y-%m-%d';
        } elseif ($days <= 90) {
            $granularity = 'week';
            $fmt = '%Y-%W';
            $mysqlFmt = '%Y-%u';
        } else {
            $granularity = 'month';
            $fmt = '%Y-%m';
            $mysqlFmt = '%Y-%m';
        }

        $periodExpression = DB::getDriverName() === 'mysql'
            ? "DATE_FORMAT(booked_at, '{$mysqlFmt}')"
            : "strftime('{$fmt}', booked_at)";

        $rows = Transfer::query()
            ->select(
                DB::raw("{$periodExpression} as period_key"),
                DB::raw('SUM(amount) as total_volume'),
                DB::raw('COUNT(*) as total_count'),
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->where('booked_at', '<=', $to)
            ->groupBy('period_key')
            ->orderBy('period_key')
            ->get();

        return [
            'labels'      => $rows->pluck('period_key')->all(),
            'volumes'     => $rows->map(fn($r) => (int) $r->total_volume)->all(),
            'counts'      => $rows->map(fn($r) => (int) $r->total_count)->all(),
            'granularity' => $granularity,
        ];
    }

    /**
     * Top N aziende per volume in un periodo.
     */
    private function topCompaniesByVolumeForPeriod(?CarbonImmutable $from, ?CarbonImmutable $to, int $limit = 10): \Illuminate\Support\Collection
    {
        $query = Transfer::query()
            ->join('accounts', 'transfers.from_account_id', '=', 'accounts.id')
            ->join('companies', 'accounts.company_id', '=', 'companies.id')
            ->select(
                'companies.name',
                'companies.slug',
                DB::raw('SUM(transfers.amount) as volume'),
                DB::raw('COUNT(*) as tx_count'),
            )
            ->where('transfers.status', 'booked')
            ->whereNotNull('accounts.company_id')
            ->when($from, fn($q) => $q->where('transfers.booked_at', '>=', $from))
            ->when($to,   fn($q) => $q->where('transfers.booked_at', '<=', $to))
            ->groupBy('companies.name', 'companies.slug')
            ->orderByDesc('volume')
            ->limit($limit);

        return $query->get();
    }

    private function authorizeBackoffice(User $user): void
    {
        abort_unless($user->canAccessBackoffice(), 403);
    }

    private function authorizePermission(User $user, string $permission): void
    {
        abort_unless($user->is_super_admin || $user->hasPermission($permission), 403);
    }
    // ── Analytics ─────────────────────────────────────────────────────────────

    public function analytics(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $days = (int) $request->input('days', 30);
        $from = now()->subDays($days);

        return view('admin.analytics', [
            'pageTitle'   => 'Analytics avanzate',
            'activeNav'   => 'analytics',
            'days'        => $days,

            // Webhook
            'webhookTotal'       => Webhook::count(),
            'webhookActive'      => Webhook::where('is_active', true)->count(),
            'webhookDeliveries'  => WebhookDelivery::where('created_at', '>=', $from)->count(),
            'webhookFailed'      => WebhookDelivery::where('created_at', '>=', $from)->where('success', false)->count(),
            'webhookByEvent'     => WebhookDelivery::where('created_at', '>=', $from)
                ->selectRaw('event, count(*) as cnt')
                ->groupBy('event')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get(),

            // API tokens
            'apiTokenTotal'      => ApiToken::count(),
            'apiTokenActive'     => ApiToken::where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'apiTokenUsedRecent' => ApiToken::where('last_used_at', '>=', $from)->count(),

            // Piani rateali
            'planTotal'          => PaymentPlan::count(),
            'planActive'         => PaymentPlan::where('status', 'active')->count(),
            'planCompleted'      => PaymentPlan::where('status', 'completed')->count(),
            'planCancelled'      => PaymentPlan::where('status', 'cancelled')->count(),
            'planPendingApproval'=> PaymentPlan::where('status', 'pending_approval')->count(),

            // Pagamenti programmati
            'schedPending'       => ScheduledPayment::where('status', 'pending')->count(),
            'schedExecuted'      => ScheduledPayment::where('status', 'executed')->count(),
            'schedFailed'        => ScheduledPayment::where('status', 'failed')->count(),
            'schedCancelled'     => ScheduledPayment::where('status', 'cancelled')->count(),
            'schedRecent'        => ScheduledPayment::where('created_at', '>=', $from)->count(),

            // Richieste testuali
            'textTotal'          => TextPaymentRequest::count(),
            'textPending'        => TextPaymentRequest::where('status', 'pending')->count(),
            'textApproved'       => TextPaymentRequest::where('status', 'approved')->count(),
            'textRejected'       => TextPaymentRequest::where('status', 'rejected')->count(),
            'textCancelled'      => TextPaymentRequest::where('status', 'cancelled')->count(),

            // Netting
            'nettingTotal'       => NettingProposal::count(),
            'nettingPending'     => NettingProposal::where('status', 'pending')->count(),
            'nettingAccepted'    => NettingProposal::where('status', 'accepted')->count(),
            'nettingRejected'    => NettingProposal::where('status', 'rejected')->count(),
            'nettingVolume'      => NettingProposal::where('status', 'accepted')->sum('net_amount'),

            // Trend mensile (dual-driver: SQLite in dev, MySQL in prod)
            'schedMonthly'       => ScheduledPayment::where('created_at', '>=', now()->subMonths(6))
                ->selectRaw(
                    \DB::getDriverName() === 'sqlite'
                        ? "strftime('%Y-%m', created_at) as month, count(*) as cnt"
                        : "DATE_FORMAT(created_at, '%Y-%m') as month, count(*) as cnt"
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
        ]);
    }



    // ── Sospensione azienda ───────────────────────────────────────────────────

    /** POST /admin/companies/{company}/suspend */
    public function suspendCompany(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'suspension_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company->update([
            'suspended_at'       => now(),
            'suspension_reason'  => $data['suspension_reason'] ?? null,
        ]);

        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event'        => 'admin.company.suspend',
            'auditable_type' => Company::class,
            'auditable_id'  => $company->id,
            'context'       => ['reason' => $data['suspension_reason'] ?? null],
        ]);

        return redirect()->route('admin.company.show', $company)
            ->with('success', 'Azienda sospesa.');
    }

    /** POST /admin/companies/{company}/unsuspend */
    public function unsuspendCompany(Request $request, Company $company): RedirectResponse
    {
        $company->update([
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.unsuspend',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return redirect()->route('admin.company.show', $company)
            ->with('success', 'Sospensione rimossa. Azienda riattivata.');
    }

    /** POST /admin/companies/{company}/activate */
    public function activateCompany(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $company->update([
            'status'     => 'active',
            'approved_at'=> $company->approved_at ?? now(),
        ]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.activate',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', 'Azienda ' . $company->name . ' attivata nel circuito.');
    }

    /** POST /admin/companies/{company}/deactivate */
    public function deactivateCompany(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $company->update(['status' => 'pending']);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.deactivate',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => [],
        ]);

        return back()->with('portal_success', 'Azienda ' . $company->name . ' disattivata.');
    }

    /** POST /admin/companies/{company}/plan */
    public function updatePlan(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeBackoffice($request->user());

        $validated = $request->validate([
            'subscription_plan' => ['nullable', 'in:ecommerce,vetrina,biglietto,anagrafica'],
        ]);

        $company->update(['subscription_plan' => $validated['subscription_plan'] ?: null]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.company.plan_updated',
            'auditable_type' => Company::class,
            'auditable_id'   => $company->id,
            'context'        => ['plan' => $validated['subscription_plan']],
        ]);

        return back()->with('portal_success', 'Piano abbonamento aggiornato.');
    }


    // ── Annullamento admin piano rateale ──────────────────────────────────────

    /** POST /admin/payment-plans/{plan}/cancel */
    public function cancelPaymentPlan(Request $request, PaymentPlan $plan): RedirectResponse
    {
        abort_unless(in_array($plan->status, ['pending_approval', 'active'], true), 422, 'Piano non annullabile in questo stato.');

        // Cancella le rate pendenti
        $plan->installments()->where('status', 'pending')->update(['status' => 'cancelled']);
        $plan->update(['status' => 'cancelled']);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.payment_plan.cancel',
            'auditable_type' => PaymentPlan::class,
            'auditable_id'   => $plan->id,
            'context'        => ['reason' => 'Annullamento forzato admin'],
        ]);

        return back()->with('success', 'Piano rateale annullato.');
    }

    // ---- Annullamento admin proposta netting ----------------------------------------

    /** POST /admin/netting/{proposal}/cancel */
    public function cancelNettingProposal(Request $request, NettingProposal $proposal): RedirectResponse
    {
        abort_unless($proposal->status === 'pending', 422, "La proposta non è più in stato pending.");

        $proposal->update(['status' => 'rejected', 'actioned_by' => $request->user()->id, 'actioned_at' => now()]);

        AuditLog::create([
            'actor_user_id'  => $request->user()->id,
            'event'         => 'admin.netting.cancel',
            'auditable_type' => NettingProposal::class,
            'auditable_id'   => $proposal->id,
            'context'        => ['reason' => 'Annullamento forzato admin'],
        ]);

        return back()->with('success', 'Proposta netting annullata.');
    }



    // -----------------------------------------------------------------------
    // Gestione sessioni utente (admin)
    // -----------------------------------------------------------------------

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

    // ── Circolazione monetaria / Velocity / Rete / Anomalie ──────────────────

    /** GET /admin/circuito */
    public function circuito(Request $request): View
    {
        $this->authorizeBackoffice($request->user());

        $days = max(7, min(365, (int) $request->input('days', 30)));
        $from = now()->subDays($days)->startOfDay();
        $now  = now();
        $drv  = DB::getDriverName();

        // ── Velocity KY ──────────────────────────────────────────────────────
        $kyInCirculation = (int) Account::where('status', 'active')
            ->where('is_system_account', false)
            ->sum('available_balance');

        $volumePeriod = (int) Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->sum('amount');

        $transactionCount = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->count();

        // Velocity = volume periodo / KY in circolazione (normalizzato a 30gg)
        $velocity30d = $kyInCirculation > 0
            ? round(($volumePeriod / $days * 30) / $kyInCirculation, 3)
            : 0;

        $rotationDays = $velocity30d > 0
            ? round(30 / $velocity30d, 1)
            : null;

        // Partecipanti attivi nel periodo (unique from + to account IDs)
        $fromIds = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->distinct()
            ->pluck('from_account_id');
        $toIds = Transfer::where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->distinct()
            ->pluck('to_account_id');
        $activeParticipants = $fromIds->merge($toIds)->unique()->count();

        $totalAccounts = Account::where('status', 'active')
            ->where('is_system_account', false)
            ->count();

        $participationRate = $totalAccounts > 0
            ? round($activeParticipants / $totalAccounts * 100, 1)
            : 0;

        // Trend velocity mensile (6 mesi)
        $periodExpr = $drv === 'mysql'
            ? "DATE_FORMAT(booked_at, '%Y-%m')"
            : "strftime('%Y-%m', booked_at)";

        $velocityTrend = Transfer::select(
                DB::raw("{$periodExpr} as ym"),
                DB::raw('SUM(amount) as volume'),
                DB::raw('COUNT(*) as cnt')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', now()->subMonths(6)->startOfMonth())
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        // Saldo medio di sistema (per calcolo velocity su base aggregata)
        $avgBalance = Account::where('status', 'active')
            ->where('is_system_account', false)
            ->avg('available_balance');

        // ── Rete transazioni (top coppie per volume) ──────────────────────────
        $networkEdges = Transfer::select(
                'from_account_id',
                'to_account_id',
                DB::raw('SUM(amount) as volume'),
                DB::raw('COUNT(*) as count')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $from)
            ->whereNotIn('kind', ['portal_fee', 'portal_cashback'])
            ->groupBy('from_account_id', 'to_account_id')
            ->orderByDesc('volume')
            ->limit(60)
            ->with(['fromAccount', 'toAccount'])
            ->get();

        // Nodi unici dalla rete
        $nodeIds = $networkEdges->pluck('from_account_id')
            ->merge($networkEdges->pluck('to_account_id'))
            ->unique();

        $nodeAccounts = Account::whereIn('id', $nodeIds)
            ->select('id', 'uuid', 'account_name', 'owner_type', 'company_id', 'owner_user_id', 'available_balance')
            ->with(['company:id,name', 'ownerUser:id,name'])
            ->get()
            ->keyBy('id');

        $networkNodes = $nodeAccounts->map(fn ($a) => [
            'id'    => $a->id,
            'label' => $a->company?->name ?? $a->ownerUser?->name ?? $a->account_name ?? "Conto #{$a->id}",
            'balance' => $a->available_balance,
        ])->values();

        $networkLinks = $networkEdges->map(fn ($e) => [
            'source' => $e->from_account_id,
            'target' => $e->to_account_id,
            'volume' => $e->volume,
            'count'  => $e->count,
        ])->values();

        // ── Anomalie automatiche ──────────────────────────────────────────────
        $anomalies = collect();

        // 1. Transazioni singole grandi (> 5× media del periodo)
        $avgAmount = $transactionCount > 0 && $volumePeriod > 0
            ? $volumePeriod / $transactionCount
            : 0;

        if ($avgAmount > 0) {
            $threshold = $avgAmount * 5;
            $largeTxs = Transfer::with(['fromAccount.company', 'toAccount.company'])
                ->where('status', 'booked')
                ->where('booked_at', '>=', $from)
                ->where('amount', '>', $threshold)
                ->orderByDesc('amount')
                ->limit(10)
                ->get();

            foreach ($largeTxs as $tx) {
                $anomalies->push([
                    'type'     => 'large_transaction',
                    'severity' => $tx->amount > $threshold * 3 ? 'high' : 'medium',
                    'title'    => 'Transazione anomala per importo',
                    'detail'   => sprintf(
                        '%s → %s: %s KY (%.0f× la media)',
                        $tx->fromAccount?->company?->name ?? $tx->fromAccount?->account_name ?? '?',
                        $tx->toAccount?->company?->name ?? $tx->toAccount?->account_name ?? '?',
                        ky_format($tx->amount),
                        $avgAmount > 0 ? $tx->amount / $avgAmount : 0
                    ),
                    'link'     => route('admin.transfers.index'),
                    'at'       => $tx->booked_at?->format('d/m/Y H:i'),
                ]);
            }
        }

        // 2. Burst: account con volume 24h > 3× loro media settimanale
        $last24h  = now()->subDay();
        $last7d   = now()->subDays(7);

        $burst24h = Transfer::select(
                'from_account_id',
                DB::raw('SUM(amount) as vol24h'),
                DB::raw('COUNT(*) as cnt24h')
            )
            ->where('status', 'booked')
            ->where('booked_at', '>=', $last24h)
            ->groupBy('from_account_id')
            ->having('cnt24h', '>=', 3)
            ->get()
            ->keyBy('from_account_id');

        if ($burst24h->isNotEmpty()) {
            $weekly = Transfer::select(
                    'from_account_id',
                    DB::raw('SUM(amount)/7.0 as avg_daily_vol')
                )
                ->where('status', 'booked')
                ->whereBetween('booked_at', [$last7d, $last24h])
                ->whereIn('from_account_id', $burst24h->keys())
                ->groupBy('from_account_id')
                ->get()
                ->keyBy('from_account_id');

            $burstAccounts = Account::whereIn('id', $burst24h->keys())
                ->with('company:id,name')
                ->get()
                ->keyBy('id');

            foreach ($burst24h as $accountId => $row) {
                $avgDaily = (float) ($weekly->get($accountId)?->avg_daily_vol ?? 0);
                if ($avgDaily > 0 && $row->vol24h > $avgDaily * 3) {
                    $account = $burstAccounts->get($accountId);
                    $anomalies->push([
                        'type'     => 'burst_activity',
                        'severity' => 'medium',
                        'title'    => 'Picco di attività insolito',
                        'detail'   => sprintf(
                            '%s: %s KY in 24h (%.0f× media giornaliera)',
                            $account?->company?->name ?? $account?->account_name ?? "Conto #{$accountId}",
                            ky_format((int) $row->vol24h),
                            $row->vol24h / $avgDaily
                        ),
                        'link'     => route('admin.accounts.show', $accountId),
                        'at'       => null,
                    ]);
                }
            }
        }

        // 3. Aziende vicine al limite di credito (saldo < 10% del massimale)
        $nearLimit = Account::with(['company:id,name', 'creditLimits'])
            ->where('status', 'active')
            ->where('is_system_account', false)
            ->where('allow_negative_balance', true)
            ->whereHas('creditLimits')
            ->get()
            ->filter(function ($a) {
                $limit = (int) ($a->activeCreditLimit()?->credit_limit ?? 0);
                return $limit > 0 && $a->available_balance < 0
                    && abs($a->available_balance) > $limit * 0.9;
            });

        foreach ($nearLimit as $a) {
            $climit = (int) ($a->activeCreditLimit()?->credit_limit ?? 0);
            $anomalies->push([
                'type'     => 'near_credit_limit',
                'severity' => 'high',
                'title'    => 'Conto al limite del fido',
                'detail'   => sprintf(
                    '%s: saldo %s KY (fido %s KY)',
                    $a->company?->name ?? $a->account_name,
                    ky_format($a->available_balance),
                    ky_format($climit)
                ),
                'link'     => route('admin.accounts.show', $a->id),
                'at'       => null,
            ]);
        }

        // 4. KYC non completato su account attivi con transazioni recenti
        $activeWithoutKyc = Account::select('accounts.id', 'accounts.account_name', 'accounts.company_id')
            ->join('transfers', function ($j) use ($from) {
                $j->on('accounts.id', '=', 'transfers.from_account_id')
                  ->where('transfers.status', 'booked')
                  ->where('transfers.booked_at', '>=', $from);
            })
            ->whereHas('company', function ($q) {
                $q->whereDoesntHave('kycDocuments', fn ($d) => $d->where('status', 'approved'))
                  ->where('status', 'active');
            })
            ->with('company:id,name')
            ->distinct()
            ->limit(10)
            ->get();

        foreach ($activeWithoutKyc as $a) {
            $anomalies->push([
                'type'     => 'kyc_missing',
                'severity' => 'medium',
                'title'    => 'KYC non approvato con attività recente',
                'detail'   => sprintf(
                    '%s ha transazioni nel periodo ma KYC non verificato',
                    $a->company?->name ?? $a->account_name
                ),
                'link'     => route('admin.kyc.index'),
                'at'       => null,
            ]);
        }

        // Ordina per severity
        $anomalies = $anomalies->sortBy(fn ($a) => match ($a['severity']) {
            'high'   => 0,
            'medium' => 1,
            default  => 2,
        })->values();

        return view('admin.circuito', [
            'pageTitle'         => 'Circolazione & Rete KY',
            'activeNav'         => 'circuito',
            'days'              => $days,

            // Velocity
            'kyInCirculation'   => $kyInCirculation,
            'volumePeriod'      => $volumePeriod,
            'transactionCount'  => $transactionCount,
            'velocity30d'       => $velocity30d,
            'rotationDays'      => $rotationDays,
            'activeParticipants'=> (int) $activeParticipants,
            'totalAccounts'     => $totalAccounts,
            'participationRate' => $participationRate,
            'avgAmount'         => $avgAmount ? (int) round($avgAmount) : 0,
            'avgBalance'        => $avgBalance ? (int) round($avgBalance) : 0,
            'velocityTrend'     => $velocityTrend,

            // Rete
            'networkNodes'      => $networkNodes,
            'networkLinks'      => $networkLinks,

            // Anomalie
            'anomalies'         => $anomalies,
        ]);
    }
}
