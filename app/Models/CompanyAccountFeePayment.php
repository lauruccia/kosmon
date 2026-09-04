<?php

namespace App\Models;

use App\Models\Contracts\FeePayment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un tentativo di pagamento della quota di apertura conto delle aziende
 * (03/09/2026).
 *
 * Gemello di RegistrationFeePayment e AgentCodeFeePayment: stessi stati,
 * stessi metodi, stesse colonne dei provider. La sola differenza e' il
 * prefisso della causale del bonifico — APERTURA-, dopo QUOTA- dei privati e
 * CODICE- degli agenti — che serve a chi guarda l'estratto conto della banca
 * per capire, senza aprire il portale, quale delle tre quote e' arrivata.
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
class CompanyAccountFeePayment extends Model implements FeePayment
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

    /**
     * Quota annullata dall'admin dopo essere stata saldata. Non e' 'failed':
     * quella dice "non e' mai stata pagata", questa dice "era pagata e
     * l'abbiamo disfatta".
     */
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
        static::creating(function (CompanyAccountFeePayment $p): void {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relazioni ───────────────────────────────────────────────────────────

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

    // ── Stato ───────────────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPendingBankTransfer(): bool
    {
        return $this->status === self::STATUS_PENDING_BANK_TRANSFER;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Pagata in euro: KNM incassa e il conto non viene toccato. Pagata in KY:
     * il conto va sotto. A differenza della quota dei privati, qui in euro non
     * si riceve nessun KY in cambio — vedi CompanyAccountFeeService.
     */
    public function isPaidInEuro(): bool
    {
        return $this->payment_method !== self::METHOD_KY;
    }

    // ── Accessor ────────────────────────────────────────────────────────────

    public function getAmountEurAttribute(): float
    {
        return $this->amount_eur_cents / 100;
    }

    /**
     * Causale del bonifico: corta, stampabile e ricollegabile al pagamento
     * senza che l'utente debba copiare un UUID intero.
     */
    public function getBankTransferReferenceAttribute(): string
    {
        return 'APERTURA-' . strtoupper(substr(str_replace('-', '', (string) $this->uuid), 0, 10));
    }
}
