<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un abbonamento che un portale del circuito addebita sul conto KMoney
 * dell'utente tramite il mandato di pagamento.
 *
 * Cosa NON è: il padrone dell'abbonamento. Prodotti, calendario e spedizione
 * stanno sul portale, che qui manda una copia a ogni cambiamento. KMoney ci
 * aggiunge una sola cosa che è sua e dell'utente: il BLOCCO.
 *
 * Bloccare non annulla niente sul portale: dice a KMoney di rifiutare ogni
 * addebito di questo abbonamento, anche se il portale ci riprova. Il portale
 * non può sbloccare (`blocked_at` non è mai scritto dall'API): per riaccendere
 * serve l'utente, con lo step-up — spegnere dev'essere più facile che accendere.
 */
class MandateSubscription extends Model
{
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE, self::PAUSED, self::CANCELLED];

    protected $fillable = [
        'uuid',
        'user_id',
        'client_id',
        'external_id',
        'title',
        'items',
        'seller_account_number',
        'amount',
        'interval_months',
        'status',
        'next_charge_at',
        'last_charged_at',
        'last_error',
        'blocked_at',
        'cancelled_at',
    ];

    protected $casts = [
        'items'           => 'array',
        'amount'          => 'integer',
        'interval_months' => 'integer',
        'next_charge_at'  => 'datetime',
        'last_charged_at' => 'datetime',
        'blocked_at'      => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MandateSubscription $subscription): void {
            $subscription->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PaymentMandateCharge::class, 'mandate_subscription_id');
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * Ancora in piedi: né annullato dal portale né bloccato dall'utente.
     * Un abbonamento in pausa è "in piedi" ma non addebita: lo dice lo stato.
     */
    public function isLive(): bool
    {
        return ! $this->isCancelled() && ! $this->isBlocked();
    }

    /**
     * Lo stato com'è mostrato all'utente: il blocco vince su tutto, perché è
     * la cosa che lo riguarda di più.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isBlocked()   => 'Bloccato',
            $this->isCancelled() => 'Annullato',
            $this->status === self::PAUSED => 'In pausa',
            default              => 'Attivo',
        };
    }

    public function intervalLabel(): string
    {
        return match ((int) $this->interval_months) {
            1       => 'ogni mese',
            12      => 'ogni anno',
            default => 'ogni ' . (int) $this->interval_months . ' mesi',
        };
    }
}
