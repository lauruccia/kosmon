<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount
 * @property string $description
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property string $status
 * @property int|null $transfer_id
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $recurrence_group
 * @property int|null $recurrence_index
 * @property int|null $recurrence_total
 * @property string|null $recurrence_type
 * @property int|null $payment_plan_installment_id
 * @property-read \App\Models\User $creator
 * @property-read \App\Models\Account $fromAccount
 * @property-read \App\Models\PaymentPlanInstallment|null $planInstallment
 * @property-read \App\Models\Account $toAccount
 * @property-read \App\Models\Transfer|null $transfer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereExecutedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereFromAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment wherePaymentPlanInstallmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereRecurrenceGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereRecurrenceIndex($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereRecurrenceTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereRecurrenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereScheduledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereToAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScheduledPayment whereUuid($value)
 * @mixin \Eloquent
 */
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
        return ky_format($this->amount) . ' KY';
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
