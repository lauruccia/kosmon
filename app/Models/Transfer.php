<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
