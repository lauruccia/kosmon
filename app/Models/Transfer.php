<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property int|null $initiated_by
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount
 * @property string $currency_code
 * @property string $status
 * @property string $kind
 * @property string $idempotency_key
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $booked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $reversed_transfer_id
 * @property \Illuminate\Support\Carbon|null $refunded_at
 * @property string|null $admin_action
 * @property int|null $related_transfer_id
 * @property int|null $confirmed_by
 * @property-read \App\Models\User|null $confirmer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Transfer> $feeTransfers
 * @property-read int|null $fee_transfers_count
 * @property-read \App\Models\Account $fromAccount
 * @property-read \App\Models\User|null $initiator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\LedgerEntry> $ledgerEntries
 * @property-read int|null $ledger_entries_count
 * @property-read Transfer|null $relatedTransfer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Transfer> $reversalChildren
 * @property-read int|null $reversal_children_count
 * @property-read Transfer|null $reversedTransfer
 * @property-read \App\Models\Account $toAccount
 * @method static \Database\Factories\TransferFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereAdminAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereBookedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereConfirmedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereCurrencyCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereFromAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereInitiatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereRefundedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereRelatedTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereReversedTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereToAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Transfer whereUuid($value)
 * @mixin \Eloquent
 */
class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'reference',
        'initiated_by',
        'confirmed_by',
        'from_account_id',
        'to_account_id',
        'amount',
        'currency_code',
        'status',
        'kind',
        'idempotency_key',
        'reversed_transfer_id',
        'related_transfer_id',
        'refunded_at',
        'admin_action',
        'description',
        'booked_at',
    ];

    protected $casts = [
        'booked_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer): void {
            $transfer->uuid ??= (string) Str::uuid();
            $transfer->reference ??= 'TRX-' . Str::upper(Str::random(12));
            $transfer->idempotency_key ??= (string) Str::uuid();
        });
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** Utente che ha confermato una richiesta di pagamento pending. */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function reversedTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transfer_id');
    }

    public function reversalChildren(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_transfer_id');
    }

    /**
     * Trasferimento padre che ha generato questa commissione (kind = portal_fee).
     */
    public function relatedTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transfer_id');
    }

    /**
     * Commissioni generate da questo trasferimento.
     */
    public function feeTransfers(): HasMany
    {
        return $this->hasMany(self::class, 'related_transfer_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
