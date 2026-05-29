<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentPlan extends Model
{
    protected $fillable = [
        'uuid',
        'initiated_by',
        'initiator_role',
        'from_account_id',
        'to_account_id',
        'total_amount',
        'currency_code',
        'installments_count',
        'frequency',
        'first_due_date',
        'description',
        'status',
    ];

    protected $casts = [
        'first_due_date' => 'date',
        'total_amount'   => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentPlan $plan): void {
            $plan->uuid ??= (string) Str::uuid();
        });
    }

    // Relationships

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->orderBy('installment_number');
    }

    // State helpers

    public function paidInstallments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->where('status', 'paid');
    }

    public function pendingInstallments(): HasMany
    {
        return $this->hasMany(PaymentPlanInstallment::class)->where('status', 'pending');
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'rejected'], true);
    }

    /**
     * L'account che ha PROPOSTO il piano.
     * initiator_role = 'debtor'   => from_account (acquirente propone)
     * initiator_role = 'creditor' => to_account   (venditore propone)
     */
    public function proposerAccount(): ?Account
    {
        return $this->initiator_role === 'creditor'
            ? $this->toAccount
            : $this->fromAccount;
    }

    /**
     * L'account che deve APPROVARE la proposta (la controparte).
     * initiator_role = 'debtor'   => to_account   (venditore deve approvare)
     * initiator_role = 'creditor' => from_account (acquirente deve approvare)
     */
    public function counterpartyAccount(): ?Account
    {
        return $this->initiator_role === 'creditor'
            ? $this->fromAccount
            : $this->toAccount;
    }

    public function canBeApprovedBy(Account $account): bool
    {
        return $this->counterpartyAccount()?->id === $account->id;
    }

    // Computed

    public function amountPaid(): int
    {
        return (int) $this->paidInstallments()->sum('amount');
    }

    public function amountRemaining(): int
    {
        return max(0, $this->total_amount - $this->amountPaid());
    }

    public function progressPercentage(): int
    {
        if ($this->total_amount === 0) {
            return 100;
        }
        return (int) round(($this->amountPaid() / $this->total_amount) * 100);
    }

    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'weekly'    => 'settimanale',
            'biweekly'  => 'bisettimanale',
            'monthly'   => 'mensile',
            default     => $this->frequency,
        };
    }

    public function initiatorRoleLabel(): string
    {
        return $this->initiator_role === 'creditor' ? 'proposto dal venditore' : 'richiesto dal compratore';
    }

    /** Build installment schedule (does not persist). */
    public static function buildSchedule(int $total, int $count, string $frequency, \Carbon\Carbon $firstDue): array
    {
        $base      = (int) floor($total / $count);
        $remainder = $total - ($base * $count);
        $schedule  = [];
        $due       = $firstDue->copy();

        for ($i = 1; $i <= $count; $i++) {
            $amount     = $base + ($i === $count ? $remainder : 0);
            $schedule[] = ['installment_number' => $i, 'amount' => $amount, 'due_date' => $due->toDateString()];
            $due        = match ($frequency) {
                'weekly'   => $due->addWeek(),
                'biweekly' => $due->addWeeks(2),
                default    => $due->addMonth(),
            };
        }

        return $schedule;
    }
}
