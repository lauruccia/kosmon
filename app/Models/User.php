<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property bool $is_active
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property bool $is_super_admin
 * @property string $account_holder_type
 * @property int|null $managed_account_id
 * @property string|null $phone
 * @property string|null $fiscal_code
 * @property int|null $circuit_capacity_limit
 * @property int|null $negative_balance_limit
 * @property int|null $daily_transaction_limit
 * @property int|null $monthly_transaction_limit
 * @property int|null $per_movement_limit
 * @property string|null $two_factor_secret
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property array<array-key, mixed>|null $two_factor_recovery_codes
 * @property bool $transfer_limits_use_defaults
 * @property array<array-key, mixed>|null $notification_preferences
 * @property string|null $pending_email
 * @property string|null $email_change_token
 * @property string|null $email_change_expires_at
 * @property \Illuminate\Support\Carbon|null $contract_signed_at
 * @property string|null $contract_otp
 * @property \Illuminate\Support\Carbon|null $contract_otp_expires_at
 * @property \Illuminate\Support\Carbon|null $contract_postponed_at
 * @property string|null $email_change_cancel_token
 * @property \Illuminate\Support\Carbon|null $tutorial_shown_at
 * @property string|null $payment_pin_hash
 * @property string|null $city
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property string|null $referral_code
 * @property int|null $referred_by_user_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Account> $assignedAccounts
 * @property-read int|null $assigned_accounts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> $auditLogs
 * @property-read int|null $audit_logs_count
 * @property-read \App\Models\Company|null $company
 * @property-read \App\Models\Account|null $managedAccount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Account> $managedSubAccounts
 * @property-read int|null $managed_sub_accounts_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Account> $ownedAccounts
 * @property-read int|null $owned_accounts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $referrals
 * @property-read int|null $referrals_count
 * @property-read User|null $referredBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WebAuthnCredential> $webAuthnCredentials
 * @property-read int|null $web_authn_credentials_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User unsignedContract()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAccountHolderType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBio($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCircuitCapacityLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereContractOtp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereContractOtpExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereContractPostponedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereContractSignedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDailyTransactionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailChangeCancelToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailChangeExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailChangeToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFiscalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsSuperAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereManagedAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMonthlyTransactionLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNegativeBalanceLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNotificationPreferences($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePaymentPinHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePendingEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePerMovementLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereReferralCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereReferredByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTransferLimitsUseDefaults($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTutorialShownAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'company_id',
        'account_holder_type',
        'managed_account_id',
        'name',
        'email',
        'email_verified_at',
        'phone',
        'city',
        'bio',
        'avatar_path',
        'fiscal_code',
        'password',
        'role',
        'is_active',
        'is_super_admin',
        'circuit_capacity_limit',
        'negative_balance_limit',
        'daily_transaction_limit',
        'monthly_transaction_limit',
        'per_movement_limit',
        'transfer_limits_use_defaults',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'two_factor_recovery_codes',
        'notification_preferences',
        'contract_signed_at',
        'contract_otp',
        'contract_otp_expires_at',
        'contract_postponed_at',
        'pending_email',
        'email_change_token',
        'email_change_expires_at',
        'email_change_cancel_token',
        'tutorial_shown_at',
        'payment_pin_hash',
        'referral_code',
        'referred_by_user_id',
        'mlm_role',
        'mlm_rank',
        'mlm_rank_updated_at',
        'mlm_activated_at',
        'mlm_basiq_at',
        'mlm_basiq_bonus_eligible',
        'mlm_client_agent_id',
        'mlm_agent_request_status',
        'mlm_agent_requested_at',
        'mlm_agent_request_note',
        'mlm_agent_reviewed_at',
        'mlm_agent_reviewed_by',
        'mlm_agent_rejection_reason',
        'mlm_agent_contract_signed_at',
        'mlm_agent_contract_otp',
        'mlm_agent_contract_otp_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        'notification_preferences' => 'array',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'transfer_limits_use_defaults' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'contract_signed_at'      => 'datetime',
            'contract_otp_expires_at'  => 'datetime',
            'contract_postponed_at'   => 'datetime',
            'tutorial_shown_at'       => 'datetime',

            'two_factor_recovery_codes' => 'array',

            'mlm_rank_updated_at'      => 'datetime',
            'mlm_activated_at'         => 'datetime',
            'mlm_basiq_at'             => 'datetime',
            'mlm_basiq_bonus_eligible' => 'boolean',

            'mlm_agent_requested_at'            => 'datetime',
            'mlm_agent_reviewed_at'              => 'datetime',
            'mlm_agent_contract_signed_at'       => 'datetime',
            'mlm_agent_contract_otp_expires_at'  => 'datetime',
        ];
    }

    /**
     * URL pubblico dell'avatar (null se non impostato).
     * Speculare a Company::getLogoUrlAttribute().
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function managedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'managed_account_id');
    }

    public function ownedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'owner_user_id');
    }

    public function assignedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'assigned_by_user_id');
    }

    public function managedSubAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_managers')
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps()
            ->wherePivotNotNull('accepted_at');
    }

    /**
     * All accounts this user can actively switch to:
     * their own root account + all managed sub-accounts.
     */
    public function switchableAccounts(): \Illuminate\Support\Collection
    {
        $accounts = collect();

        // Own root account (via company or owner_user_id)
        if ($this->company_id) {
            $own = Account::query()
                ->where('company_id', $this->company_id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->first();
            if ($own) {
                $accounts->push($own);
            }
        } elseif ($this->managed_account_id === null) {
            $own = Account::query()
                ->where('owner_user_id', $this->id)
                ->whereNull('parent_account_id')
                ->where('status', 'active')
                ->first();
            if ($own) {
                $accounts->push($own);
            }
        }

        // Managed sub-accounts
        $sub = $this->managedSubAccounts()
            ->where('status', 'active')
            ->whereNotNull('parent_account_id')
            ->get();
        $accounts = $accounts->merge($sub);

        return $accounts->unique('id')->values();
    }

    public function canManageSubAccount(Account $subAccount): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        if ($this->canCreateSubaccountsFor($subAccount->parentAccount ?? $subAccount)) {
            return true;
        }

        return $this->managedSubAccounts()
            ->whereKey($subAccount->id)
            ->wherePivot('role', 'manager')
            ->exists();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function webAuthnCredentials(): HasMany
    {
        return $this->hasMany(WebAuthnCredential::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->slug === $slug) || $this->role === $slug;
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        $hasRolePermission = $this->roles->contains(function (Role $role) use ($slug) {
            return $role->permissions->contains(fn (Permission $permission) => $permission->slug === $slug);
        });

        if ($hasRolePermission) {
            return true;
        }

        return in_array($slug, $this->legacyPermissions(), true);
    }

    public function canAccessBackoffice(): bool
    {
        return $this->is_super_admin || $this->hasPermission('backoffice.access');
    }

    public function canAccessPortal(): bool
    {
        return ! $this->canAccessBackoffice();
    }

    public function canSendFromAccount(Account $account): bool
    {
        if ($this->managed_account_id === $account->id) {
            return $this->hasPermission('payments.send');
        }

        if ($account->owner_type === 'private') {
            return $account->owner_user_id === $this->id && $this->hasPermission('payments.send');
        }

        return $this->company_id !== null
            && $this->company_id === $account->company_id
            && $this->hasPermission('payments.send');
    }

    public function canReceiveIntoAccount(Account $account): bool
    {
        if ($this->managed_account_id === $account->id) {
            return $this->hasPermission('payments.receive');
        }

        if ($account->owner_type === 'private') {
            return $account->owner_user_id === $this->id && $this->hasPermission('payments.receive');
        }

        return $this->company_id !== null
            && $this->company_id === $account->company_id
            && $this->hasPermission('payments.receive');
    }

    public function canOperateAccount(Account $account): bool
    {
        return $this->canSendFromAccount($account) || $this->canReceiveIntoAccount($account);
    }

    /**
     * Can the user switch to / operate on this account (own or delegated via account_managers)?
     */
    public function canOperateOnAccount(Account $account): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        // Legacy single delegate
        if ($this->managed_account_id === $account->id) {
            return true;
        }

        // Own company root account
        if ($account->company_id && $this->company_id === $account->company_id && $account->parent_account_id === null) {
            return true;
        }

        // Own private account
        if ($account->owner_type === 'private' && $account->owner_user_id === $this->id) {
            return true;
        }

        // Multi-delegate: sub-account managed via account_managers
        return \App\Models\AccountManager::where('account_id', $account->id)
            ->where('user_id', $this->id)
            ->whereNotNull('accepted_at')
            ->exists();
    }

    public function canCreateSubaccountsFor(Account $account): bool
    {
        $rootAccount = $account->parentAccount ?? $account;

        if ($rootAccount->owner_type === 'private') {
            return $rootAccount->owner_user_id === $this->id;
        }

        return $this->company_id !== null
            && $this->company_id === $rootAccount->company_id
            && ($this->hasPermission('accounts.manage') || $this->hasPermission('users.manage'));
    }

    public function canViewCompaniesDirectory(): bool
    {
        return $this->hasPermission('companies.read');
    }

    public function canAccessAnnouncements(): bool
    {
        return $this->hasPermission('announcements.read') || $this->hasPermission('announcements.publish');
    }

    public function canAccessMarketplace(): bool
    {
        return $this->hasPermission('marketplace.buy') || $this->hasPermission('marketplace.sell');
    }

    public function effectiveTransferLimits(): array
    {
        $defaults = SystemSetting::userLimitDefaults()->defaultsMap();

        if (! $this->transfer_limits_use_defaults) {
            return [
                'circuit_capacity_limit' => $this->circuit_capacity_limit,
                'negative_balance_limit' => $this->negative_balance_limit,
                'daily_transaction_limit' => $this->daily_transaction_limit,
                'monthly_transaction_limit' => $this->monthly_transaction_limit,
                'per_movement_limit' => $this->per_movement_limit,
            ];
        }

        return [
            'circuit_capacity_limit' => $this->circuit_capacity_limit ?? $defaults['circuit_capacity_limit'],
            'negative_balance_limit' => $this->negative_balance_limit ?? $defaults['negative_balance_limit'],
            'daily_transaction_limit' => $this->daily_transaction_limit ?? $defaults['daily_transaction_limit'],
            'monthly_transaction_limit' => $this->monthly_transaction_limit ?? $defaults['monthly_transaction_limit'],
            'per_movement_limit' => $this->per_movement_limit ?? $defaults['per_movement_limit'],
        ];
    }

    public function hasCustomTransferLimits(): bool
    {
        return ! $this->transfer_limits_use_defaults
            || $this->circuit_capacity_limit !== null
            || $this->negative_balance_limit !== null
            || $this->daily_transaction_limit !== null
            || $this->monthly_transaction_limit !== null
            || $this->per_movement_limit !== null;
    }

    /**
     * Transitional compatibility for legacy role labels already present in local data.
     * New behavior should come from explicit roles and permissions.
     *
     * @return list<string>
     */
    private function legacyPermissions(): array
    {
        return match ($this->role) {
            'owner', 'admin', 'treasurer', 'company-manager' => [
                'payments.send',
                'payments.receive',
                'movements.read',
                'accounts.read',
                'accounts.manage',
                'users.read',
                'users.manage',
                'companies.read',
                'announcements.read',
                'announcements.publish',
                'marketplace.buy',
                'marketplace.sell',
            ],
            'viewer', 'company-viewer' => [
                'companies.read',
                'movements.read',
            ],
            'private-owner', 'private-member', 'registered-private' => [
                'payments.send',
                'payments.receive',
                'movements.read',
                'companies.read',
            ],
            'employee', 'family-member', 'delegate', 'delegate-member', 'delegated-user', 'company-member', 'registered-company' => [
                'payments.send',
                'payments.receive',
                'movements.read',
            ],
            'backoffice-operator' => [
                'backoffice.access',
                'users.read',
                'roles.read',
                'companies.read',
                'accounts.read',
                'movements.read',
                'movements.manage',
            ],
            default => [],
        };
    }

    /** Controlla se l'utente ha firmato il contratto di adesione. */
    public function hasSignedContract(): bool
    {
        return $this->contract_signed_at !== null;
    }

    /** Scope: utenti che non hanno ancora firmato il contratto. */
    public function scopeUnsignedContract($query)
    {
        return $query->whereNull('contract_signed_at');
    }

    // ── Referral ─────────────────────────────────────────────────────────────

    /** Utente che ha invitato questo utente. */
    public function referredBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_user_id');
    }

    /** Utenti invitati da questo utente. */
    public function referrals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'referred_by_user_id');
    }

    /**
     * Restituisce il codice referral, generandolo se non esiste.
     * Formato: 8 caratteri alfanumerici maiuscoli.
     */
    public function referralCode(): string
    {
        if (! $this->referral_code) {
            do {
                $code = strtoupper(\Illuminate\Support\Str::random(8));
            } while (self::where('referral_code', $code)->exists());

            $this->forceFill(['referral_code' => $code])->save();
        }

        return $this->referral_code;
    }

    /** URL di registrazione con codice referral pre-compilato. */
    public function referralUrl(): string
    {
        return route('register', ['ref' => $this->referralCode()]);
    }

    // --- MLM ---

    /** Ordine crescente delle qualifiche agente (indice = "livello" numerico). */
    public const MLM_RANK_ORDER = ['start', 'basic', 'key', 'senior', 'top', 'supervisor', 'manager'];

    public function isMlmAgent(): bool
    {
        return $this->mlm_role === 'agente';
    }

    public function isMlmClient(): bool
    {
        return $this->mlm_role === 'cliente';
    }

    /** Indice numerico della qualifica attuale (0 = start ... 6 = manager). */
    public function mlmRankLevel(): int
    {
        $index = array_search($this->mlm_rank, self::MLM_RANK_ORDER, true);

        return $index === false ? 0 : $index;
    }

    /** Agente "risolto" a cui e' collegato questo cliente (null se questo utente e' un agente). */
    public function mlmClientAgent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'mlm_client_agent_id');
    }

    /** Clienti attribuiti a questo agente. */
    public function mlmClients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'mlm_client_agent_id');
    }

    public function mlmPointLedgerEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmPointLedgerEntry::class, 'agent_user_id');
    }

    /** Punti/agenti "omaggio" assegnati da un admin (mai scadenza, vedi MlmMetricGrant). */
    public function mlmMetricGrants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmMetricGrant::class, 'agent_user_id');
    }

    /** Somma dei grant "omaggio" ATTIVI per una metrica qualsiasi (vedi MlmMetricGrant::METRICS). */
    public function mlmGrantedMetric(string $metric): int
    {
        return \App\Models\MlmMetricGrant::activeSumFor($this->id, $metric);
    }

    /** Punti "omaggio" ATTIVI (non revocati) assegnati da un admin — mai scadenza. */
    public function mlmGrantedPoints(): int
    {
        return $this->mlmGrantedMetric('points');
    }

    /** "Basic al 1° livello" omaggio ATTIVI (non revocati) assegnati da un admin — mai scadenza. */
    public function mlmGrantedLevel1Basic(): int
    {
        return $this->mlmGrantedMetric('level1_basic_count');
    }

    // --- Programma agenti: richiesta + contratto ---

    /**
     * True se questo utente puo' presentare (o ripresentare) la richiesta di
     * adesione al programma agenti KNM: non e' gia' agente e non ha una
     * richiesta pending o gia' approvata in attesa di firma contratto.
     */
    public function canRequestMlmAgent(): bool
    {
        return ! $this->isMlmAgent()
            && ! in_array($this->mlm_agent_request_status, ['pending', 'approved'], true);
    }

    public function hasPendingMlmAgentRequest(): bool
    {
        return $this->mlm_agent_request_status === 'pending';
    }

    public function hasRejectedMlmAgentRequest(): bool
    {
        return $this->mlm_agent_request_status === 'rejected';
    }

    /** Richiesta approvata dall'admin ma contratto agente non ancora firmato. */
    public function mlmAgentAwaitingContract(): bool
    {
        return $this->mlm_agent_request_status === 'approved'
            && ! $this->isMlmAgent()
            && ! $this->mlm_agent_contract_signed_at;
    }

    public function mlmAgentReviewedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'mlm_agent_reviewed_by');
    }

    public function mlmAgentContractSignatures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmAgentContractSignature::class, 'user_id');
    }

    public function mlmRankHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmRankHistory::class, 'agent_user_id');
    }

    public function mlmBonusPayouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmBonusPayout::class, 'beneficiary_user_id');
    }

    /** Righe del ledger "importo mensile" generate dai MIEI clienti diretti. */
    public function mlmCommissionBaseLedgerEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmCommissionBaseLedgerEntry::class, 'direct_agent_id');
    }

    /** Importo mensile commissionabile attivo oggi sui MIEI clienti diretti (EUR centesimi). */
    public function mlmActiveCommissionBase(?\Illuminate\Support\Carbon $asOf = null): int
    {
        $asOf ??= now();

        return (int) $this->mlmCommissionBaseLedgerEntries()
            ->whereDate('valid_from', '<=', $asOf->toDateString())
            ->whereDate('valid_until', '>=', $asOf->toDateString())
            ->sum('monthly_amount_eur_cents');
    }

    public function mlmCommissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmCommission::class, 'agent_user_id');
    }

    public function mlmPayouts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\MlmPayout::class, 'agent_user_id');
    }

    public function mlmPaymentDetail(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\MlmPaymentDetail::class, 'agent_user_id');
    }

    /**
     * Punti cliente (PC) attivi ora (somma del ledger non ancora scaduto) PIU'
     * gli eventuali punti "omaggio" assegnati da un admin (MlmMetricGrant,
     * 2026-07-14): questi ultimi non scadono mai, ma contano solo da quando
     * sono stati assegnati in poi (created_at <= $asOf) — cosi' un regalo
     * fatto oggi non altera retroattivamente il gating di mesi passati
     * quando questo metodo viene chiamato con un $asOf storico (vedi
     * MlmCommissionEngine).
     *
     * Confronto a precisione di DATETIME esatta (non solo data) dal 2026-07-13:
     * permette all'admin di impostare una scadenza punti anche di pochi
     * minuti per verificare subito il calcolo qualifiche in test (vedi
     * SystemSetting::mlmSettings()->mlm_points_validity_override_minutes e
     * MlmPointsService). In produzione (nessun override) la durata resta
     * quella di sempre, solo con granularita' fine anziche' whereDate().
     */
    public function mlmActivePoints(?\Illuminate\Support\Carbon $asOf = null): int
    {
        $asOf ??= now();

        $ledgerPoints = (int) $this->mlmPointLedgerEntries()
            ->where('valid_from', '<=', $asOf)
            ->where('valid_until', '>=', $asOf)
            ->sum('points');

        $grantedPoints = (int) $this->mlmMetricGrants()
            ->where('metric', 'points')
            ->whereNull('revoked_at')
            ->where('created_at', '<=', $asOf)
            ->sum('amount');

        return $ledgerPoints + $grantedPoints;
    }

    /** Invia la notifica di reset password in italiano con il layout brandizzato. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    /** Invia la notifica di verifica email in italiano con il layout brandizzato. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new \App\Notifications\VerifyEmailNotification());
    }
}
