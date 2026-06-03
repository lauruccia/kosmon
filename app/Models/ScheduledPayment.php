<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'recurrence_group',
        'recurrence_index',
        'recurrence_total',
        'recurrence_type',
        'payment_plan_installment_id',
    ];

    protected $casts = [
        'scheduled_at'      => 'datetime',
        'executed_at'       => 'datetime',
        'amount'            => 'integer',
        'recurrence_index'  => 'integer',
        'recurrence_total'  => 'integer',
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

    public function planInstallment(): BelongsTo
    {
        return $this->belongsTo(PaymentPlanInstallment::class, 'payment_plan_installment_id');
    }

    // ── Stato ─────────────────────────────────────────────────────────────────

    // ── Ricorrenza ────────────────────────────────────────────────────────────

    public function isRecurring(): bool
    {
        return $this->recurrence_group !== null;
    }

    /** True se questa scheduled payment è la rappresentazione di una rata di un piano rateale. */
    public function isPlanInstallment(): bool
    {
        return $this->payment_plan_installment_id !== null;
    }

    /** Etichetta leggibile della frequenza. */
    public function recurrenceTypeLabel(): string
    {
        return match ($this->recurrence_type) {
            'monthly'  => 'Mensile',
            'weekly'   => 'Settimanale',
            'biweekly' => 'Bisettimanale',
            default    => ucfirst($this->recurrence_type ?? ''),
        };
    }

    /** Tutte le rate dello stesso gruppo (ordinate). */
    public function siblings(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->recurrence_group) {
            return collect();
        }

        return static::where('recurrence_group', $this->recurrence_group)
            ->orderBy('recurrence_index')
            ->get();
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
