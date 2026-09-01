<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Registra il pagamento della differenza quando un'azienda passa (upgrade)
 * a un piano di abbonamento con canone piu' alto. Ricalca deliberatamente
 * lo schema di KyCardPurchase (stessi metodi di pagamento: Stripe, PayPal,
 * bonifico, o KY interno se il piano lo consente) per restare coerente col
 * resto del circuito.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $user_id
 * @property int|null $from_plan_id
 * @property int $to_plan_id
 * @property int $amount_cents
 * @property string $status
 * @property string $payment_method
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $paypal_order_id
 * @property int|null $ky_transfer_id
 * @property string|null $admin_notes
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class PlanPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'company_id', 'user_id', 'from_plan_id', 'to_plan_id',
        'amount_cents', 'status', 'payment_method',
        'stripe_checkout_session_id', 'stripe_payment_intent_id', 'paypal_order_id',
        'ky_transfer_id', 'admin_notes', 'confirmed_by', 'completed_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PlanPayment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function fromPlan(): BelongsTo { return $this->belongsTo(Plan::class, 'from_plan_id'); }
    public function toPlan(): BelongsTo { return $this->belongsTo(Plan::class, 'to_plan_id'); }
    public function confirmedBy(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function kyTransfer(): BelongsTo { return $this->belongsTo(Transfer::class, 'ky_transfer_id'); }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isPendingBankTransfer(): bool { return $this->status === 'pending_bank_transfer'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
    /** Annullato: lo schema della tabella lo prevede, oggi nessun codice lo
     *  scrive. La guardia c'e' lo stesso, perche' il giorno che qualcuno
     *  scrivera' 'cancelled' un webhook in ritardo non deve resuscitarlo. */
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isAwaitingPayment(): bool { return in_array($this->status, ['pending', 'pending_bank_transfer'], true); }

    /** Causale univoca per il bonifico. */
    public function getBankTransferReferenceAttribute(): string
    {
        return 'KMPIANO-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
