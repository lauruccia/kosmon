<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount
 * @property string $causale
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $due_date
 * @property string $status
 * @property int|null $transfer_id
 * @property int $created_by
 * @property int|null $actioned_by
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actioner
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Account $fromAccount
 * @property-read \App\Models\Account $toAccount
 * @property-read \App\Models\Transfer|null $transfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereActionedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereActionedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereCausale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereDueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereFromAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereToAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TextPaymentRequest whereUuid($value)
 * @mixin \Eloquent
 */
class TextPaymentRequest extends Model
{
    protected $fillable = [
        'uuid',
        'from_account_id',
        'to_account_id',
        'amount',
        'causale',
        'note',
        'due_date',
        'status',
        'transfer_id',
        'created_by',
        'actioned_by',
        'actioned_at',
    ];

    protected $casts = [
        'due_date'    => 'date',
        'actioned_at' => 'datetime',
        'amount'      => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // ── Relazioni ──────────────────────────────────────────────────────────────

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function actioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by');
    }

    // ── Stato ─────────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->isPending() && $this->due_date && $this->due_date->isPast());
    }

    public function isActionable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    // Chi puo' approvare/rifiutare: il debitore (to_account)
    public function canBeActionedBy(Account $account): bool
    {
        return (int) $this->to_account_id === $account->id;
    }

    // Chi puo' cancellare: il creditore (from_account)
    public function canBeCancelledBy(Account $account): bool
    {
        return (int) $this->from_account_id === $account->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function formattedAmount(): string
    {
        return ky_format($this->amount) . ' KY';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'In attesa',
            'approved'  => 'Approvata',
            'rejected'  => 'Rifiutata',
            'cancelled' => 'Annullata',
            'expired'   => 'Scaduta',
            default     => ucfirst($this->status),
        };
    }

    public function statusChipClass(): string
    {
        return match ($this->status) {
            'approved' => 'success',
            'rejected', 'cancelled', 'expired' => 'pink',
            default => '',
        };
    }
}
