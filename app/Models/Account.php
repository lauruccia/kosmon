<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BalanceAlert;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\SubAccountInvitation;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $company_id
 * @property string $type
 * @property string $currency_code
 * @property string $status
 * @property bool $allow_negative_balance
 * @property int $available_balance
 * @property int $pending_balance
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $owner_user_id
 * @property string $owner_type
 * @property int|null $parent_account_id
 * @property int|null $assigned_by_user_id
 * @property string|null $account_name
 * @property int|null $spending_limit
 * @property int|null $daily_outgoing_limit
 * @property bool $is_system_account
 * @property int|null $max_balance
 * @property int|null $monthly_outgoing_limit
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property string $card_status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubAccountInvitation> $activeInvitations
 * @property-read int|null $active_invitations_count
 * @property-read \App\Models\User|null $assignedByUser
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BalanceAlert> $balanceAlerts
 * @property-read int|null $balance_alerts_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Account> $childAccounts
 * @property-read int|null $child_accounts_count
 * @property-read \App\Models\Company|null $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CreditLimitRequest> $creditLimitRequests
 * @property-read int|null $credit_limit_requests_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CreditLimit> $creditLimits
 * @property-read int|null $credit_limits_count
 * @property-read string $account_number
 * @property-read string $account_type
 * @property-read string $display_name
 * @property-read bool $is_subaccount
 * @property-read string $owner_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Transfer> $incomingTransfers
 * @property-read int|null $incoming_transfers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubAccountInvitation> $invitations
 * @property-read int|null $invitations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LedgerEntry> $ledgerEntries
 * @property-read int|null $ledger_entries_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $managedUsers
 * @property-read int|null $managed_users_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $managers
 * @property-read int|null $managers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Transfer> $outgoingTransfers
 * @property-read int|null $outgoing_transfers_count
 * @property-read \App\Models\User|null $ownerUser
 * @property-read Account|null $parentAccount
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $pendingManagers
 * @property-read int|null $pending_managers_count
 * @method static \Database\Factories\AccountFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereAccountName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereAllowNegativeBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereAssignedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereAvailableBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCardStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereDailyOutgoingLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereIsSystemAccount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereLockedUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereMaxBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereMonthlyOutgoingLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereOwnerType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereOwnerUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereParentAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account wherePendingBalance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereSpendingLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Account whereUuid($value)
 * @mixin \Eloquent
 */
class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'company_id',
        'owner_user_id',
        'owner_type',
        'parent_account_id',
        'assigned_by_user_id',
        'type',
        'account_name',
        'currency_code',
        'status',
        'card_status',
        'allow_negative_balance',
        'is_system_account',
        'available_balance',
        'pending_balance',
        'max_balance',
        'spending_limit',
        'daily_outgoing_limit',
        'monthly_outgoing_limit',
        'locked_until',
    ];

    protected $casts = [
        'allow_negative_balance' => 'boolean',
        'is_system_account'      => 'boolean',
        'locked_until'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Account $account): void {
            if (! static::hasKyAccountNumber($account->uuid)) {
                $account->uuid = static::generateKyAccountNumber($account->owner_type ?? 'company');
            }

            // Limite giornaliero di default 500 KY per i conti non-sistema
            if (! $account->is_system_account && $account->daily_outgoing_limit === null) {
                $account->daily_outgoing_limit = 50000;
            }
        });
    }

    // ── Accessor: account_type (KYB / KYP / KY) derivato da owner_type ───────

    /**
     * Restituisce il prefisso del numero di conto (KYB, KYP, KY) basandosi su owner_type.
     * Usato come colonna virtuale nei controller al posto di una colonna DB.
     */
    public function getAccountTypeAttribute(): string
    {
        return match ($this->owner_type) {
            'private' => 'KYP',
            'company' => 'KYB',
            default   => 'KY',
        };
    }

    // ── Blocco temporaneo anti-frode ─────────────────────────────────────────

    /**
     * L'account è attualmente bloccato per attività anomala?
     */
    public function isTemporarilyLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Blocca l'account per $minutes minuti.
     */
    public function lockTemporarily(int $minutes = 30): void
    {
        $this->forceFill(['locked_until' => now()->addMinutes($minutes)])->save();
    }

    public static function hasKyAccountNumber(?string $value): bool
    {
        // Formati validi (tutti 16 char):
        //   KYB + 13 alfanumerici maiuscoli  →  conti business
        //   KYP + 13 alfanumerici maiuscoli  →  conti privati
        //   KY  + 14 alfanumerici maiuscoli  →  conti sistema
        return is_string($value)
            && preg_match('/^(KY[BP][A-Z0-9]{13}|KY[A-Z0-9]{14})$/', $value) === 1;
    }

    public static function generateKyAccountNumber(string $ownerType = 'company'): string
    {
        // KYB = Business (azienda), KYP = Personal (privato), KY = altri (sistema)
        $prefix  = match ($ownerType) {
            'private' => 'KYP',
            'company' => 'KYB',
            default   => 'KY',
        };
        $fillLen = 16 - strlen($prefix); // 13 per KYB/KYP, 14 per KY
        do {
            $candidate = $prefix . Str::upper(Str::random($fillLen));
        } while (static::query()->where('uuid', $candidate)->exists());

        return $candidate;
    }

    /**
     * Restituisce il conto riserva del circuito (Cassa Circuito KMoney).
     * Usato per l'emissione sovrana di KY da parte dell'admin.
     */
    public static function systemAccount(): ?self
    {
        return static::query()->where('is_system_account', true)->first();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function childAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function managers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_managers')
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps()
            ->wherePivotNotNull('accepted_at');
    }

    public function pendingManagers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_managers')
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps()
            ->wherePivotNull('accepted_at');
    }

    public function invitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SubAccountInvitation::class);
    }

    public function activeInvitations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SubAccountInvitation::class)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    public function isSubAccount(): bool
    {
        return $this->parent_account_id !== null;
    }

    public function spentToday(): int
    {
        return (int) Transfer::query()
            ->where('from_account_id', $this->id)
            ->where('status', 'booked')
            ->whereBetween('booked_at', [
                \Carbon\CarbonImmutable::now()->startOfDay(),
                \Carbon\CarbonImmutable::now()->endOfDay(),
            ])
            ->sum('amount');
    }

    public function spentThisMonth(): int
    {
        return (int) Transfer::query()
            ->where('from_account_id', $this->id)
            ->where('status', 'booked')
            ->whereBetween('booked_at', [
                \Carbon\CarbonImmutable::now()->startOfMonth(),
                \Carbon\CarbonImmutable::now()->endOfMonth(),
            ])
            ->sum('amount');
    }

    /**
     * Centesimi KY che possono ancora essere inviati oggi (null = nessun limite configurato).
     */
    public function remainingToday(): ?int
    {
        if ($this->daily_outgoing_limit === null) {
            return null;
        }
        return max(0, $this->daily_outgoing_limit - $this->spentToday());
    }

    /**
     * Centesimi KY che possono ancora essere inviati questo mese (null = nessun limite configurato).
     */
    public function remainingThisMonth(): ?int
    {
        if ($this->monthly_outgoing_limit === null) {
            return null;
        }
        return max(0, $this->monthly_outgoing_limit - $this->spentThisMonth());
    }

    public function hasReachedDailyLimit(int $amount): bool
    {
        if ($this->daily_outgoing_limit === null) {
            return false;
        }
        return ($this->spentToday() + $amount) > $this->daily_outgoing_limit;
    }

    public function hasReachedMonthlyLimit(int $amount): bool
    {
        if ($this->monthly_outgoing_limit === null) {
            return false;
        }
        return ($this->spentThisMonth() + $amount) > $this->monthly_outgoing_limit;
    }

    public function assertSubAccountSpendingLimits(int $amount): void
    {
        if ($this->spending_limit !== null && $amount > $this->spending_limit) {
            throw new \RuntimeException(
                'Il pagamento supera il limite per singola operazione del sottoconto (' . ky_format($this->spending_limit) . ' KY).'
            );
        }
        if ($this->hasReachedDailyLimit($amount)) {
            throw new \RuntimeException(
                'Il pagamento supera il limite giornaliero del sottoconto (' . ky_format($this->daily_outgoing_limit) . ' KY).'
            );
        }
        if ($this->hasReachedMonthlyLimit($amount)) {
            throw new \RuntimeException(
                'Il pagamento supera il limite mensile del sottoconto (' . ky_format($this->monthly_outgoing_limit) . ' KY).'
            );
        }
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function managedUsers(): HasMany
    {
        return $this->hasMany(User::class, 'managed_account_id');
    }

    public function creditLimits(): HasMany
    {
        return $this->hasMany(CreditLimit::class);
    }

    public function creditLimitRequests(): HasMany
    {
        return $this->hasMany(CreditLimitRequest::class);
    }

    public function pendingCreditLimitRequest(): ?CreditLimitRequest
    {
        return $this->creditLimitRequests()->where('status', 'pending')->latest()->first();
    }

    public function activeCreditLimit(): ?CreditLimit
    {
        return $this->creditLimits()
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'from_account_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'to_account_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function ownerTransferLimits(): array
    {
        return $this->ownerUser?->effectiveTransferLimits() ?? [
            'circuit_capacity_limit' => 0,
            'negative_balance_limit' => 0,
            'daily_transaction_limit' => null,
            'monthly_transaction_limit' => null,
            'per_movement_limit' => null,
        ];
    }

    public function massimale(): int
    {
        if ($this->parent_account_id !== null) {
            return 0;
        }

        $accountCreditLimit = (int) ($this->activeCreditLimit()?->credit_limit ?? 0);
        $ownerNegativeBalanceLimit = (int) ($this->ownerTransferLimits()['negative_balance_limit'] ?? 0);

        return max(0, $accountCreditLimit, $ownerNegativeBalanceLimit);
    }

    public function saldoDisponibile(): int
    {
        return (int) $this->available_balance + $this->massimale();
    }

    // ---- Regole commerciali bilancio (stile Sardex) ----------------------

    /**
     * L'azienda e' in debito: saldo sotto zero.
     * In questo stato puo' vendere solo al 100% KY.
     */
    public function isInDebit(): bool
    {
        return (int) $this->available_balance < 0;
    }

    /**
     * Il conto ha raggiunto il tetto massimo configurato dall'admin.
     * In questo stato l'azienda puo' solo acquistare, non vendere.
     */
    public function isAtCeiling(): bool
    {
        if ($this->max_balance === null) {
            return false;
        }
        return (int) $this->available_balance >= (int) $this->max_balance;
    }

    /**
     * L'azienda puo' pubblicare/vendere prodotti nel circuito?
     */
    public function canSell(): bool
    {
        return ! $this->isAtCeiling();
    }

    /**
     * Percentuali KY consentite per le vendite, in base al saldo.
     *
     * - Saldo < 0        => [100]              (solo 100% KY, obbligatorio)
     * - 0 <= saldo < max => [0, 25, 50, 75, 100] (libera scelta)
     * - Saldo >= max     => []                 (vendita bloccata)
     *
     * @return int[]
     */
    public function allowedKyPercentages(): array
    {
        if (! $this->canSell()) {
            return [];
        }
        if ($this->isInDebit()) {
            return [100];
        }
        return [0, 25, 50, 75, 100];
    }

    /**
     * Percentuale KY imposta forzatamente, o null se libera scelta.
     */
    public function requiredKyPercentage(): ?int
    {
        if (! $this->canSell()) {
            return null;
        }
        if ($this->isInDebit()) {
            return 100;
        }
        return null;
    }

    /**
     * Badge stato commerciale per UI admin e portale.
     *
     * @return array{label: string, color: string}
     */
    public function commercialStatusBadge(): array
    {
        if ($this->isAtCeiling()) {
            return ['label' => 'Tetto raggiunto — solo acquisti', 'color' => 'red'];
        }
        if ($this->isInDebit()) {
            return ['label' => 'In debito — solo 100% KY', 'color' => 'yellow'];
        }
        return ['label' => 'Libera vendita', 'color' => 'green'];
    }


    public function disponibilitaCommerciale(): int
    {
        if ($this->parent_account_id !== null) {
            return 0;
        }

        // Saldo positivo (KY ricevuti + ricariche KYCard) + fido attivo
        return max(0, (int) $this->available_balance) + $this->massimale();
    }

    public function disponibilitaCommercialeUsata(?CarbonImmutable $since = null): int
    {
        $limit = $this->disponibilitaCommerciale();

        if ($limit <= 0) {
            return 0;
        }

        $since ??= CarbonImmutable::now()->startOfYear();

        return (int) Transfer::query()
            ->where('to_account_id', $this->id)
            ->where('status', 'booked')
            ->where('booked_at', '>=', $since)
            ->sum('amount');
    }

    public function disponibilitaCommercialeResidua(?CarbonImmutable $since = null): int
    {
        return max(0, $this->disponibilitaCommerciale() - $this->disponibilitaCommercialeUsata($since));
    }

    public function disponibilitaCommercialePercentualeUtilizzo(?CarbonImmutable $since = null): float
    {
        $limit = $this->disponibilitaCommerciale();

        if ($limit <= 0) {
            return 0.0;
        }

        return min(100, round(($this->disponibilitaCommercialeUsata($since) / $limit) * 100, 2));
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->account_name) {
            return preg_replace('/^Conto (principale|personale) /u', '', $this->account_name);
        }

        if ($this->owner_type === 'private') {
            return $this->ownerUser?->name ? 'Conto di ' . $this->ownerUser->name : 'Conto privato';
        }

        return $this->company?->name ?? 'Conto KMoney';
    }

    public function getOwnerLabelAttribute(): string
    {
        if ($this->owner_type === 'private') {
            return $this->ownerUser?->name ?? 'Profilo privato';
        }

        return $this->company?->name ?? 'Profilo aziendale';
    }

    public function getIsSubaccountAttribute(): bool
    {
        return $this->parent_account_id !== null;
    }

    public function getAccountNumberAttribute(): string
    {
        if (static::hasKyAccountNumber($this->uuid)) {
            return $this->uuid;
        }

        if ($this->id) {
            return 'KY' . str_pad((string) $this->id, 14, '0', STR_PAD_LEFT);
        }

        return static::generateKyAccountNumber();
    }
    public function balanceAlerts(): HasMany
    {
        return $this->hasMany(BalanceAlert::class);
    }

}
