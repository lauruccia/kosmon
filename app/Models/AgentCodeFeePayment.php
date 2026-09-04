<?php

namespace App\Models;

use App\Models\Contracts\FeePayment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un tentativo di pagamento della quota per il codice agente (31/08/2026).
 *
 * Gemello di RegistrationFeePayment e tenuto separato per scelta di Laura: le
 * due quote sono attive insieme e vanno lette come due cose distinte, anche a
 * costo di due tabelle che si somigliano.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $account_id
 * @property int $amount_eur_cents
 * @property int $ky_amount
 * @property string $status
 * @property string $payment_method
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $paypal_order_id
 * @property int|null $transfer_id
 * @property string|null $admin_notes
 * @property int|null $confirmed_by
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class AgentCodeFeePayment extends Model implements FeePayment
{
    use HasFactory;

    public const METHOD_STRIPE = 'stripe';
    public const METHOD_PAYPAL = 'paypal';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_KY = 'ky';

    public const METHODS = [
        self::METHOD_STRIPE        => 'Carta di credito (Stripe)',
        self::METHOD_PAYPAL        => 'PayPal',
        self::METHOD_BANK_TRANSFER => 'Bonifico bancario',
        self::METHOD_KY            => 'Saldo KY',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PENDING_BANK_TRANSFER = 'pending_bank_transfer';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    /** Annullata dal backoffice: AgentCodeFeeService::cancel() (01/09/2026). */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'user_id', 'account_id',
        'amount_eur_cents', 'ky_amount', 'status', 'payment_method',
        'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'paypal_order_id', 'transfer_id', 'admin_notes', 'confirmed_by',
        'completed_at',
    ];

    protected $casts = [
        'amount_eur_cents' => 'integer',
        'ky_amount'        => 'integer',
        'completed_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentCodeFeePayment $p): void {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPendingBankTransfer(): bool
    {
        return $this->status === self::STATUS_PENDING_BANK_TRANSFER;
    }

    public function isPaidInEuro(): bool
    {
        return $this->payment_method !== self::METHOD_KY;
    }

    public function getAmountEurAttribute(): float
    {
        return $this->amount_eur_cents / 100;
    }

    public function getBankTransferReferenceAttribute(): string
    {
        return 'CODICE-' . strtoupper(substr(str_replace('-', '', (string) $this->uuid), 0, 10));
    }
}
