<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPlanInstallment extends Model
{
    protected $fillable = [
        'payment_plan_id',
        'installment_number',
        'amount',
        'due_date',
        'status',
        'transfer_id',
        'processed_at',
        'failure_reason',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'processed_at' => 'datetime',
        'amount'       => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_date->isPast();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'In attesa',
            'paid'      => 'Pagata',
            'failed'    => 'Fallita',
            'cancelled' => 'Annullata',
            default     => ucfirst($this->status),
        };
    }

    public function statusChipClass(): string
    {
        return match ($this->status) {
            'paid'      => 'success',
            'failed'    => 'pink',
            'cancelled' => '',
            default     => '',
        };
    }
}
