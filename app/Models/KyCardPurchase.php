<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $ky_card_id
 * @property int $account_id
 * @property int $user_id
 * @property int $price_eur_cents
 * @property int $ky_amount
 * @property string $status
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property int|null $transfer_id
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $payment_method
 * @property string|null $paypal_order_id
 * @property string|null $admin_notes
 * @property int|null $confirmed_by
 * @property-read \App\Models\Account $account
 * @property-read string $bank_transfer_reference
 * @property-read float $price_eur
 * @property-read \App\Models\KyCard $kyCard
 * @property-read \App\Models\Transfer|null $transfer
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereAdminNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereConfirmedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereKyAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereKyCardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase wherePaypalOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase wherePriceEurCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereStripeCheckoutSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereStripePaymentIntentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereTransferId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCardPurchase whereUuid($value)
 * @mixin \Eloquent
 */
class KyCardPurchase extends Model
{
    protected $fillable = [
        'uuid', 'ky_card_id', 'account_id', 'user_id',
        'price_eur_cents', 'ky_amount', 'status', 'payment_method',
        'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'paypal_order_id', 'admin_notes', 'confirmed_by',
        'transfer_id', 'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (KyCardPurchase $p) {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relazioni ──────────────────────────────────────────────────────────

    public function kyCard(): BelongsTo   { return $this->belongsTo(KyCard::class); }
    public function account(): BelongsTo  { return $this->belongsTo(Account::class); }
    public function user(): BelongsTo     { return $this->belongsTo(User::class); }
    public function transfer(): BelongsTo { return $this->belongsTo(Transfer::class); }

    // ── Helper ─────────────────────────────────────────────────────────────

    public function isPending(): bool            { return $this->status === 'pending'; }
    public function isPendingBankTransfer(): bool { return $this->status === 'pending_bank_transfer'; }
    public function isCompleted(): bool          { return $this->status === 'completed'; }
    public function isFailed(): bool             { return $this->status === 'failed'; }
    public function isAwaitingPayment(): bool    { return in_array($this->status, ['pending', 'pending_bank_transfer']); }

    /** Causale univoca per il bonifico */
    public function getBankTransferReferenceAttribute(): string
    {
        return 'KYC-' . strtoupper(substr($this->uuid, 0, 8));
    }

    public function getPriceEurAttribute(): float
    {
        return $this->price_eur_cents / 100;
    }
}
