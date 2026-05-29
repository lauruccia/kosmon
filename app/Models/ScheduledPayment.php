<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ScheduledPayment extends Model
{
    protected $fillable = [
        'uuid',
        'from_account_id',
        'to_account_id',
        'amount',
        'description',
        'scheduled_at',
        'status',
        'transfer_id',
        'failure_reason',
        'executed_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'executed_at'  => 'datetime',
        'amount'       => 'integer',
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

    // ── Stato ─────────────────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === 'pending'; }
    public function isExecuted(): bool  { return $this->status === 'executed'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isFailed(): bool    { return $this->status === 'failed'; }

    public function isDue(): bool
    {
        return $this->isPending() && $this->scheduled_at->isPast();
    }

    public function canBeCancelledBy(Account $account): bool
    {
        return $this->isPending() && (int) $this->from_account_id === $account->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function formattedAmount(): string
    {
        return number_format($this->amount, 2, ',', '.') . ' KY';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'In attesa',
            'executed'  => 'Eseguito',
            'cancelled' => 'Annullato',
            'failed'    => 'Fallito',
            default     => ucfirst($this->status),
        };
    }

    public function statusChipClass(): string
    {
        return match ($this->status) {
            'executed' => 'success',
            'failed', 'cancelled' => 'pink',
            default => '',
        };
    }
}
