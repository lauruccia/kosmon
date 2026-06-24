<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $payment_plan_id
 * @property int $installment_number
 * @property int $amount
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $status
 * @property int|null $transfer_id
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PaymentPlan $paymentPlan
 * @property-read \App\Models\ScheduledPayment|null $scheduledPayment
 * @property-read \App\Models\Transfer|null $transfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereDueDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereInstallmentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment wherePaymentPlanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentPlanInstallment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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

    /** La scheduled payment creata al momento dell'approvazione del piano, se presente. */
    public function scheduledPayment(): HasOne
    {
        return $this->hasOne(ScheduledPayment::class, 'payment_plan_installment_id');
    }

    /** True se questa rata è gestita da una ScheduledPayment ancora in attesa. */
    public function hasPendingScheduledPayment(): bool
    {
        return $this->scheduledPayment()
            ->where('status', 'pending')
            ->exists();
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
