<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Un addebito eseguito grazie a un mandato: il legame fra il permesso e il
 * movimento vero, che resta nei `transfers`.
 *
 * Serve a tre cose insieme — idempotenza (la stessa chiave non addebita due
 * volte), antifurto (quanti addebiti nell'ultima ora) e trasparenza (l'elenco
 * che l'utente vede in "App collegate", distinto dagli acquisti confermati a
 * mano).
 */
class PaymentMandateCharge extends Model
{
    protected $fillable = [
        'uuid',
        'payment_mandate_id',
        'transfer_id',
        'amount',
        'seller_account_number',
        'external_order_uuid',
        'order_title',
        'quantity',
        'idempotency_key',
        'created_ip',
    ];

    protected $casts = [
        'amount'   => 'integer',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentMandateCharge $charge): void {
            $charge->uuid ??= (string) Str::uuid();
        });
    }

    public function mandate(): BelongsTo
    {
        return $this->belongsTo(PaymentMandate::class, 'payment_mandate_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /**
     * Presente solo se questo addebito è nato da una conferma dell'utente
     * invece che dal "un clic e paghi". È quello che distingue i due casi
     * ovunque serva: nell'antifurto (che conta solo gli automatici) e
     * nell'elenco che l'utente vede in "App collegate".
     */
    public function mandatePaymentRequest(): HasOne
    {
        return $this->hasOne(MandatePaymentRequest::class, 'payment_mandate_charge_id');
    }

    public function wasConfirmedByUser(): bool
    {
        return $this->mandatePaymentRequest()->exists();
    }
}
