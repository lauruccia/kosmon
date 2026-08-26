<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Un ordine dello shop: che cosa ha comprato una persona, da UN venditore.
 *
 * Introdotto nella fase B del piano carrello (PIANO_CARRELLO_VARIANTI.md).
 * Prima di allora l'ordine non esisteva come oggetto: era il movimento
 * bancario, e questo bastava finché un acquisto era un prodotto solo.
 *
 * Regola che tiene tutto in piedi: **un ordine ha un solo venditore**. Un
 * carrello con prodotti di tre aziende diventerà tre ordini, ognuno col suo
 * movimento KY. Da qui discende tutto il resto — un ordine, un movimento, al
 * massimo una quota in euro.
 *
 * Il denaro NON vive qui: `total_ky` è una copia comoda di `transfers.amount`,
 * non la verità. La verità contabile resta il ledger, e questa tabella la
 * banca non la legge nemmeno.
 *
 * Tutti gli importi sono in CENTESIMI.
 *
 * @property int $id
 * @property string $uuid
 * @property int $buyer_account_id
 * @property int|null $buyer_user_id
 * @property int $company_id
 * @property int $seller_account_id
 * @property string $status
 * @property int $total_ky
 * @property int $total_eur
 * @property int $shipping_ky
 * @property int $shipping_eur
 * @property string|null $shipping_recipient_name
 * @property string|null $shipping_address
 * @property string|null $shipping_city
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_province
 * @property string|null $shipping_phone
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $placed_at
 * @property \Illuminate\Support\Carbon|null $backfilled_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property-read Company $company
 * @property-read Account $buyerAccount
 * @property-read Account $sellerAccount
 * @property-read Transfer|null $transfer
 * @property-read MarketplaceOrderPayment|null $payment
 */
class Order extends Model
{
    use HasFactory;

    /** C'è ancora una quota in euro da saldare fuori dal circuito. */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /** Non c'è più niente da incassare: KY mossi ed euro (se c'erano) saldati. */
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT => 'In attesa del pagamento in euro',
        self::STATUS_PAID            => 'Pagato',
    ];

    protected $fillable = [
        'uuid',
        'buyer_account_id',
        'buyer_user_id',
        'company_id',
        'seller_account_id',
        'status',
        'total_ky',
        'total_eur',
        'shipping_ky',
        'shipping_eur',
        'shipping_recipient_name',
        'shipping_address',
        'shipping_city',
        'shipping_postal_code',
        'shipping_province',
        'shipping_phone',
        'buyer_note',
        'source',
        'placed_at',
        'backfilled_at',
    ];

    protected $casts = [
        'total_ky'      => 'integer',
        'total_eur'     => 'integer',
        'shipping_ky'   => 'integer',
        'shipping_eur'  => 'integer',
        'placed_at'     => 'datetime',
        'backfilled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (! $order->uuid) {
                $order->uuid = (string) Str::uuid();
            }
            if (! $order->placed_at) {
                $order->placed_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function buyerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'buyer_account_id');
    }

    public function sellerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'seller_account_id');
    }

    public function buyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /** Il movimento KY che ha pagato questo ordine. */
    public function transfer(): HasOne
    {
        return $this->hasOne(Transfer::class);
    }

    /** La quota in euro, se il mix del prodotto ne prevedeva una. */
    public function payment(): HasOne
    {
        return $this->hasOne(MarketplaceOrderPayment::class);
    }

    // ── Comodità per le view ─────────────────────────────────────────────────

    /**
     * Ordine RICOSTRUITO dai movimenti storici invece che registrato al momento
     * dell'acquisto (fase B, 25/08/2026). Il prezzo unitario delle sue righe è
     * dedotto dividendo il totale per la quantità: va bene per mostrarlo, non
     * per farci sopra un ricalcolo.
     */
    public function isBackfilled(): bool
    {
        return $this->backfilled_at !== null;
    }

    public function hasEuroQuota(): bool
    {
        return $this->total_eur > 0;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    /**
     * Titolo breve da mostrare nelle liste: il prodotto se ce n'è uno solo,
     * altrimenti il primo più il conto degli altri.
     */
    public function getSummaryTitleAttribute(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();
        $primo = $items->first();

        if (! $primo) {
            return 'Ordine ' . substr((string) $this->uuid, 0, 8);
        }

        $altri = $items->count() - 1;
        $titolo = $primo->titolo_completo;

        return $altri > 0
            ? $titolo . ' + altri ' . $altri
            : $titolo;
    }
}
