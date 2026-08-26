<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BalanceAlert;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
        'shipping_recipient_name',
        'shipping_address',
        'shipping_city',
        'shipping_postal_code',
        'shipping_province',
        'shipping_phone',
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

        // 13/08/2026 (richiesta di Laura): quando il saldo di un conto business
        // principale cambia, riallinea automaticamente il mix KY/EUR dei
        // prodotti shop dell'azienda — 100% forzato appena si va in debito,
        // ripristino della percentuale scelta dal negozio appena se ne esce.
        // Agganciato qui sull'evento Eloquent `saved`, e non dentro
        // TransferBookingService, per coprire TUTTI i punti che toccano
        // available_balance (book, confirmRequest, refundMerchant,
        // issueCreditNote, netting, bookFee, ...) da un solo posto, senza
        // rischiare di modificare il motore finanziario. Gira nella STESSA
        // transazione DB del movimento che ha causato il cambio saldo (ogni
        // save() di Account in TransferBookingService avviene dentro
        // DB::transaction()), quindi se il movimento viene rollback-ato lo è
        // anche questo. Vedi Account::syncListingsKyPercentage().
        static::saved(function (Account $account): void {
            if ($account->wasChanged('available_balance')) {
                $account->syncListingsKyPercentage();
            }

            // 25/08/2026 (fase 2b, §3.2 del piano): la stessa notizia che qui
            // sopra viene scritta a mano nel catalogo interno va detta anche
            // alle applicazioni del circuito, che il catalogo ce l'hanno a casa
            // loro. Il calcolo resta qui — chi riceve esegue soltanto.
            //
            // Tre cose cambiano lo stato commerciale, non una: il saldo (un
            // movimento), il tetto massimo (l'admin che lo alza o lo abbassa) e
            // lo stato del conto (una sospensione). Guardare solo il saldo
            // lascerebbe kshop convinto che un'azienda sospesa possa vendere.
            if ($account->wasChanged(['available_balance', 'max_balance', 'status'])) {
                $account->notifyTradingStatusChanged();
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
    /**
     * Il conto su cui questo utente opera nel portale.
     *
     * Estratto il 25/08/2026 (fase C): la stessa identica logica viveva in tre
     * posti diversi come metodo privato di controller, e ora serve anche al
     * layout per il numerino del carrello. Il comportamento non cambia di una
     * virgola — e' lo stesso codice, spostato dove si puo' chiamare una volta
     * sola.
     */
    public static function operativoPer($user): ?self
    {
        if (! $user) {
            return null;
        }

        if ($user->managed_account_id) {
            return self::query()->with(['company', 'ownerUser'])->find($user->managed_account_id);
        }

        if ($user->company_id) {
            return self::query()->with(['company'])
                ->where('company_id', $user->company_id)
                ->whereNull('parent_account_id')
                ->first();
        }

        return self::query()->with(['ownerUser'])
            ->where('owner_user_id', $user->id)
            ->whereNull('parent_account_id')
            ->first();
    }

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

    /**
     * La rubrica degli indirizzi di spedizione (fase A-bis, 26/08/2026).
     * Le colonne `shipping_*` qui sopra restano la COPIA del predefinito:
     * chi scrive e' sempre e solo ShippingAddressBook.
     */
    public function shippingAddresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ShippingAddress::class);
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
     * - Saldo < 0        => [100]               (solo 100% KY, obbligatorio)
     * - 0 <= saldo < max => [25, 50, 75, 100]    (libera scelta)
     * - Saldo >= max     => []                  (vendita bloccata)
     *
     * 12/08/2026: rimosso 0 (100% EUR) su richiesta di Laura — non serve,
     * un prodotto shop deve avere sempre una quota KY minima del 25%.
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
        return [25, 50, 75, 100];
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
     * Questo conto è il conto business principale di un'azienda (stesso
     * filtro di Company::primaryBusinessAccount())? Solo per questi conti
     * hanno senso le regole commerciali (isInDebit/allowedKyPercentages) sui
     * prodotti shop dell'azienda — i sottoconti e il conto sistema non
     * vendono prodotti propri.
     */
    public function isPrimaryBusinessAccount(): bool
    {
        return ! $this->is_system_account
            && $this->owner_type === 'company'
            && $this->parent_account_id === null
            && $this->company_id !== null;
    }

    /**
     * Riallinea il mix KY/EUR (ky_percentage) dei prodotti shop dell'azienda
     * allo stato commerciale corrente del conto — chiamato automaticamente
     * da booted() ogni volta che available_balance cambia (vedi sopra).
     *
     * - In debito (isInDebit()): forza ky_percentage a 100 su tutti i
     *   prodotti dell'azienda, salvando il valore precedente in
     *   desired_ky_percentage (solo per i prodotti non già a 100, così non
     *   sovrascriviamo un 100% genuino con se stesso).
     * - Fuori dal debito: ripristina ky_percentage al valore desiderato
     *   (desired_ky_percentage), cioè l'ultima percentuale scelta
     *   liberamente dal negozio mentre non era in debito.
     *
     * Il caso "tetto massimo raggiunto" (isAtCeiling()) NON viene toccato
     * qui, volutamente: è un comportamento preesistente e fuori scope,
     * la richiesta di Laura del 13/08/2026 riguarda solo il ciclo
     * debito/non-debito.
     */
    public function syncListingsKyPercentage(): void
    {
        if (! $this->isPrimaryBusinessAccount()) {
            return;
        }

        if ($this->isInDebit()) {
            Listing::query()
                ->where('company_id', $this->company_id)
                ->where('ky_percentage', '!=', 100)
                ->update([
                    'desired_ky_percentage' => DB::raw('ky_percentage'),
                    'ky_percentage'         => 100,
                ]);
            return;
        }

        Listing::query()
            ->where('company_id', $this->company_id)
            ->whereColumn('ky_percentage', '!=', 'desired_ky_percentage')
            ->update([
                'ky_percentage' => DB::raw('desired_ky_percentage'),
            ]);
    }

    // ---- Stato commerciale, detto in una parola sola ----------------------

    /** Vende come vuole: sceglie il mix KY fra 25, 50, 75 e 100. */
    public const TRADING_STATUS_FREE = 'free';

    /** Saldo sotto zero: può vendere, ma solo al 100% KY. */
    public const TRADING_STATUS_IN_DEBIT = 'in_debit';

    /** Tetto massimo raggiunto: può solo comprare. */
    public const TRADING_STATUS_AT_CEILING = 'at_ceiling';

    /** Conto non attivo: non vende e non incassa. */
    public const TRADING_STATUS_SUSPENDED = 'suspended';

    /**
     * Le tre regole commerciali (`isInDebit`, `isAtCeiling`, conto sospeso)
     * ridotte a una parola sola, perché è quello che serve a chi il catalogo
     * ce l'ha altrove: non può chiamare `isInDebit()` da un'altra applicazione.
     *
     * L'ordine di precedenza è lo stesso di `commercialStatusBadge()`, e non è
     * arbitrario: un conto sospeso non vende comunque, e chi ha toccato il
     * tetto non vende nemmeno al 100% KY.
     */
    public function tradingStatus(): string
    {
        return self::tradingStatusFor(
            (string) $this->status,
            (int) $this->available_balance,
            $this->max_balance === null ? null : (int) $this->max_balance,
        );
    }

    /**
     * Lo stesso calcolo su valori sciolti, per poterlo fare anche sul PASSATO:
     * `getOriginal()` restituisce i valori di prima del salvataggio, ed è così
     * che si capisce se lo stato è davvero cambiato invece di spedire un
     * webhook a ogni singolo movimento.
     */
    public static function tradingStatusFor(string $status, int $availableBalance, ?int $maxBalance): string
    {
        if ($status !== 'active') {
            return self::TRADING_STATUS_SUSPENDED;
        }

        if ($maxBalance !== null && $availableBalance >= $maxBalance) {
            return self::TRADING_STATUS_AT_CEILING;
        }

        if ($availableBalance < 0) {
            return self::TRADING_STATUS_IN_DEBIT;
        }

        return self::TRADING_STATUS_FREE;
    }

    /**
     * Avvisa le applicazioni del circuito che questa azienda ha cambiato stato
     * commerciale — ma solo se è cambiato davvero.
     *
     * Il filtro non è un'ottimizzazione: senza, ogni movimento di ogni azienda
     * genererebbe un webhook, e un canale che grida sempre è un canale che
     * nessuno ascolta.
     */
    public function notifyTradingStatusChanged(): void
    {
        if (! $this->isPrimaryBusinessAccount()) {
            return;
        }

        $precedente = self::tradingStatusFor(
            (string) ($this->getOriginal('status') ?? $this->status),
            (int) ($this->getOriginal('available_balance') ?? 0),
            $this->getOriginal('max_balance') === null ? null : (int) $this->getOriginal('max_balance'),
        );

        $attuale = $this->tradingStatus();

        if ($precedente === $attuale) {
            return;
        }

        app(\App\Services\TradingStatusNotifier::class)->announce($this, $precedente, $attuale);
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

    // ── Indirizzo di spedizione (2026-07-29) ─────────────────────────────────

    /**
     * true se il conto ha un indirizzo di spedizione utilizzabile. Nome
     * destinatario, via, città e CAP sono i campi minimi indispensabili per
     * una spedizione reale; provincia e telefono restano facoltativi.
     */
    public function hasShippingAddress(): bool
    {
        return filled($this->shipping_recipient_name)
            && filled($this->shipping_address)
            && filled($this->shipping_city)
            && filled($this->shipping_postal_code);
    }

    /**
     * Rappresentazione multi-riga dell'indirizzo di spedizione, per le view
     * (riepilogo in checkout, dettaglio ordine per il venditore, ecc.).
     * Ritorna un array di righe già pronte da stampare, vuoto se l'indirizzo
     * non è (ancora) completo.
     */
    public function getShippingAddressLinesAttribute(): array
    {
        if (! $this->hasShippingAddress()) {
            return [];
        }

        $cityLine = trim($this->shipping_postal_code . ' ' . $this->shipping_city
            . ($this->shipping_province ? ' (' . $this->shipping_province . ')' : ''));

        $lines = [
            $this->shipping_recipient_name,
            $this->shipping_address,
            $cityLine,
        ];

        if ($this->shipping_phone) {
            $lines[] = 'Tel. ' . $this->shipping_phone;
        }

        return $lines;
    }

}
