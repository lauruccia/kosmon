<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ciclo di vita del pagamento EUR (quota non-KY) di un ordine shop.
 *
 * Creato subito dopo l'addebito KY (ListingController::buy()) quando il
 * prodotto ha una quota EUR da pagare, con status "pending" finché
 * l'acquirente non sceglie un metodo. Separato dal Transfer (che resta
 * dedicato solo al movimento KY nel circuito).
 *
 * @property int $id
 * @property string $uuid
 * @property int $transfer_id
 * @property int|null $listing_id
 * @property int $company_id
 * @property int|null $payment_gateway_id
 * @property string|null $provider
 * @property int $amount
 * @property string $currency_code
 * @property string $status
 * @property string|null $provider_reference
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property int|null $confirmed_by_user_id
 * @property-read \App\Models\Transfer $transfer
 * @property-read \App\Models\Listing|null $listing
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\PaymentGateway|null $paymentGateway
 * @property-read \App\Models\User|null $confirmedByUser
 */
class MarketplaceOrderPayment extends Model
{
    use HasFactory;

    public const STATUS_PENDING              = 'pending';   // creato, acquirente non ha ancora scelto un metodo
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation'; // metodo scelto, in attesa di conferma (es. bonifico in transito, o redirect Stripe/PayPal non ancora tornato)
    public const STATUS_PAID                 = 'paid';
    public const STATUS_FAILED               = 'failed';
    public const STATUS_CANCELLED            = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING               => 'In attesa di scelta metodo',
        self::STATUS_AWAITING_CONFIRMATION => 'In attesa di conferma',
        self::STATUS_PAID                  => 'Pagato',
        self::STATUS_FAILED                => 'Fallito',
        self::STATUS_CANCELLED             => 'Annullato',
    ];

    protected $fillable = [
        'uuid',
        'transfer_id',
        'order_id',
        'listing_id',
        'company_id',
        'payment_gateway_id',
        'provider',
        'amount',
        'currency_code',
        'status',
        'provider_reference',
        'paid_at',
        'confirmed_by_user_id',
    ];

    protected $casts = [
        'amount'  => 'integer',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketplaceOrderPayment $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });

        // Quando la quota in euro risulta incassata, l'ordine smette di essere
        // "in attesa del pagamento in euro" (audit 26/08/2026, 1.2).
        //
        // Prima questo non succedeva da nessuna parte: `orders.status` veniva
        // scritto UNA volta sola, alla creazione (OrderService::place), e i tre
        // punti che incassano gli euro - conferma manuale del bonifico
        // (PaymentController), ritorno di Stripe, ritorno di PayPal -
        // aggiornavano solo questa riga. L'ordine restava `pending_payment`
        // per sempre, anche a euro arrivati.
        //
        // Sta QUI e non nei tre chiamanti di proposito: e' esattamente
        // dimenticandosene in uno che il buco si e' aperto, e domani i punti
        // che incassano potrebbero essere quattro. Non muove denaro, allinea
        // solo uno stato derivato, ed e' idempotente: rieseguirlo non cambia
        // niente.
        static::updated(function (MarketplaceOrderPayment $payment): void {
            if (! $payment->wasChanged('status') || $payment->status !== self::STATUS_PAID) {
                return;
            }

            $order = $payment->order;

            // I pagamenti piu' vecchi del backfill non hanno un ordine, e un
            // ordine gia' rimborsato non deve tornare "pagato".
            if (! $order || $order->status !== \App\Models\Order::STATUS_PENDING_PAYMENT) {
                return;
            }

            $order->forceFill(['status' => \App\Models\Order::STATUS_PAID])->save();
        });
    }

    /**
     * L'ordine a cui questa quota in euro appartiene (fase B, 25/08/2026).
     * Puo' essere null solo sui pagamenti piu' vecchi del backfill.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Order::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
