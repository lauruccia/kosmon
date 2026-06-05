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

            'two_factor_recovery_codes' => 'array',
        ];
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
