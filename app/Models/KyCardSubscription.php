<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Abbonamento Stripe di una KYCard: a rate (si chiude da solo dopo N addebiti)
 * o a rinnovo mensile (continua finche' il cliente non disdice).
 *
 * @property int $id
 * @property string $uuid
 * @property int $ky_card_id
 * @property int $account_id
 * @property int $user_id
 * @property string $kind
 * @property int|null $installments_total
 * @property int $installments_paid
 * @property int $amount_cents
 * @property int $card_price_cents
 * @property int $ky_total
 * @property string $status
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_customer_id
 * @property string|null $stripe_subscription_id
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $next_billing_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $last_error
 */
class KyCardSubscription extends Model
{
    public const KIND_INSTALLMENTS = 'installments';
    public const KIND_RECURRING    = 'recurring';

    public const PENDING    = 'pending';
    public const ACTIVE     = 'active';
    public const COMPLETED  = 'completed';
    public const PAST_DUE   = 'past_due';
    public const DEFAULTED  = 'defaulted';
    public const CANCELLING = 'cancelling';
    public const CANCELLED  = 'cancelled';

    protected $fillable = [
        'uuid', 'ky_card_id', 'account_id', 'user_id',
        'kind', 'installments_total', 'installments_paid',
        'amount_cents', 'card_price_cents', 'ky_total', 'status',
        'stripe_checkout_session_id', 'stripe_customer_id', 'stripe_subscription_id',
        'started_at', 'next_billing_at', 'ended_at', 'last_error',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'next_billing_at' => 'datetime',
        'ended_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (KyCardSubscription $s): void {
            if (empty($s->uuid)) {
                $s->uuid = (string) Str::uuid();
            }
        });
    }

    public function kyCard(): BelongsTo  { return $this->belongsTo(KyCard::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
    public function purchases(): HasMany { return $this->hasMany(KyCardPurchase::class, 'ky_card_subscription_id'); }

    public function isInstallments(): bool { return $this->kind === self::KIND_INSTALLMENTS; }
    public function isRecurring(): bool    { return $this->kind === self::KIND_RECURRING; }

    /** Stati finali: nessun altro addebito atteso. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::DEFAULTED, self::CANCELLED], true);
    }

    public function getAmountEurAttribute(): float { return $this->amount_cents / 100; }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::PENDING    => 'In attesa del primo pagamento',
            self::ACTIVE     => $this->isInstallments() ? 'In corso' : 'Attivo',
            self::COMPLETED  => 'Completato',
            self::PAST_DUE   => 'Pagamento in ritardo',
            self::DEFAULTED  => 'Rata non pagata',
            self::CANCELLING => 'Disdetto (scade a fine periodo)',
            self::CANCELLED  => 'Disdetto',
            default          => $this->status,
        };
    }
}
