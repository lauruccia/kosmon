<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
