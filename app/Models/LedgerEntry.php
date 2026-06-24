<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $transfer_id
 * @property int $account_id
 * @property string $direction
 * @property int $amount
 * @property int $balance_after
 * @property \Illuminate\Support\Carbon $posted_at
 * @property array<array-key, mixed>|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Account $account
 * @property-read \App\Models\Transfer $transfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereBalanceAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereDirection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry wherePostedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LedgerEntry whereUuid($value)
 * @mixin \Eloquent
 */
class LedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'transfer_id',
        'account_id',
        'direction',
        'amount',
        'balance_after',
        'posted_at',
        'meta',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}