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

    /**
     * Il movimento KY e' stato rimborsato per intero e la merce e' tornata in
     * magazzino (audit 26/08/2026, 1.3). Solo sul rimborso TOTALE: su uno
     * parziale non si puo' sapere quanti pezzi siano tornati indietro, quindi
     * l'ordine resta com'era e le scorte non si toccano.
     *
     * Attenzione: riguarda solo i KY. Se l'ordine aveva anche una quota in
     * euro gia' incassata, quella va restituita fuori dal circuito - il
     * messaggio al venditore lo ricorda.
     */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Il percorso della merce (fase B, 27/08/2026).
     *
     * `preparing` -> `shipped` -> `delivered` sono ETICHETTE: non muovono un
     * centesimo, l'addebito e' gia' avvenuto alla cassa. `cancelled` invece
     * muove soldi, ed e' per questo che l'azione non esiste ancora: arriva nel
     * giro successivo, trattata come un rimborso vero.
     */
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_PAYMENT => 'In attesa del pagamento in euro',
        self::STATUS_PAID            => 'Pagato',
        self::STATUS_PREPARING       => 'In preparazione',
        self::STATUS_SHIPPED         => 'Spedito',
        self::STATUS_DELIVERED       => 'Consegnato',
        self::STATUS_CANCELLED       => 'Annullato',
        self::STATUS_REFUNDED        => 'Rimborsato',
    ];

    /**
     * I passaggi che il VENDITORE puo' fare, e solo in avanti.
     *
     * Decisione di Laura del 27/08: lo stato lo cambiano il venditore e
     * l'admin, come su WooCommerce e Shopify - non il compratore. E solo in
     * avanti, perche' un negozio che torna indietro sugli stati e' un negozio
     * che sta rimediando a un errore: per quello c'e' l'admin, che puo'
     * portare un ordine dove serve. Cosi' la regola resta di una riga sola e
     * la correzione ha un responsabile.
     *
     * Da `pending_payment` non si parte: finche' la quota in euro non e'
     * arrivata, il venditore non deve preparare niente. E' una protezione per
     * lui, non un vincolo burocratico.
     */
    /**
     * Gli stati del percorso di consegna, quelli che NON muovono denaro.
     *
     * E' il perimetro dentro cui l'admin puo' muoversi liberamente per
     * correggere: `cancelled` e `refunded` restano fuori perche' sono rimborsi
     * veri, e `pending_payment` perche' dichiarare "pagato" un ordine che non
     * ha incassato sarebbe una bugia, non una correzione.
     */
    public const STATI_DI_CONSEGNA = [
        self::STATUS_PAID,
        self::STATUS_PREPARING,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
    ];

    public const PASSAGGI_DEL_VENDITORE = [
        self::STATUS_PAID      => [self::STATUS_PREPARING, self::STATUS_SHIPPED],
        self::STATUS_PREPARING => [self::STATUS_SHIPPED],
        self::STATUS_SHIPPED   => [self::STATUS_DELIVERED],
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
        'carrier',
        'tracking_code',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'cancel_reason',
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
        'shipped_at'    => 'datetime',
        'delivered_at'  => 'datetime',
        'cancelled_at'  => 'datetime',
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

    // ── Il ciclo di vita ─────────────────────────────────────────────────────

    /**
     * L'admin puo' portare questo ordine allo stato chiesto?
     *
     * Decisione di Laura del 27/08: gli ordini li gestisce anche l'admin, per
     * conto delle aziende. A differenza del venditore puo' andare anche
     * ALL'INDIETRO - ed e' tutto il punto: e' l'unico modo di rimediare a un
     * "spedito" premuto per sbaglio, ed e' il motivo per cui il venditore puo'
     * andare solo avanti.
     *
     * Il perimetro pero' resta quello della consegna: un ordine fermo in
     * attesa della quota in euro non ci entra nemmeno per mano dell'admin, e
     * annullamenti e rimborsi restano fuori (muovono soldi: giro 2).
     */
    public function lAdminPuoPortarloA(string $nuovo): bool
    {
        return in_array($this->status, self::STATI_DI_CONSEGNA, true)
            && in_array($nuovo, self::STATI_DI_CONSEGNA, true)
            && $nuovo !== $this->status;
    }

    /**
     * Gli stati fra cui l'admin puo' scegliere adesso, con la loro etichetta.
     *
     * @return array<string, string>
     */
    public function passaggiPerAdmin(): array
    {
        if (! in_array($this->status, self::STATI_DI_CONSEGNA, true)) {
            return [];
        }

        $passaggi = [];

        foreach (self::STATI_DI_CONSEGNA as $stato) {
            if ($stato !== $this->status) {
                $passaggi[$stato] = self::STATUSES[$stato];
            }
        }

        return $passaggi;
    }

    /** Il venditore puo' portare questo ordine allo stato chiesto? */
    public function ilVenditorePuoPortarloA(string $nuovo): bool
    {
        return in_array($nuovo, self::PASSAGGI_DEL_VENDITORE[$this->status] ?? [], true);
    }

    /**
     * I passaggi che il venditore puo' fare adesso, con la loro etichetta.
     * E' quello che la pagina del venditore trasforma in bottoni: se qui non
     * c'e' niente, non c'e' niente da premere.
     *
     * @return array<string, string>
     */
    public function passaggiDisponibili(): array
    {
        $passaggi = [];

        foreach (self::PASSAGGI_DEL_VENDITORE[$this->status] ?? [] as $stato) {
            $passaggi[$stato] = self::STATUSES[$stato];
        }

        return $passaggi;
    }

    /**
     * L'ordine e' chiuso: non c'e' piu' niente da fare, in nessuna direzione.
     * Serve alle due pagine per separare "da lavorare" da "storico".
     */
    public function isConcluso(): bool
    {
        return in_array($this->status, [
            self::STATUS_DELIVERED,
            self::STATUS_CANCELLED,
            self::STATUS_REFUNDED,
        ], true);
    }

    /** Aspetta ancora la quota in euro: il venditore non deve preparare niente. */
    public function isInAttesaDiEuro(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT;
    }

    public function isSpedito(): bool
    {
        return in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_DELIVERED], true);
    }

    /** Questo ordine va spedito da qualche parte? (i servizi e i ritiri no) */
    public function richiedeSpedizione(): bool
    {
        return filled($this->shipping_address);
    }

    /**
     * Il colore con cui lo stato si legge a colpo d'occhio in un elenco.
     * Deliberatamente pochi: verde = a posto, giallo = tocca a qualcuno,
     * grigio = chiuso, rosso = finito male.
     */
    public function getStatusToneAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_PAYMENT => 'attesa',
            self::STATUS_PAID,
            self::STATUS_PREPARING       => 'lavorazione',
            self::STATUS_SHIPPED         => 'viaggio',
            self::STATUS_DELIVERED       => 'concluso',
            self::STATUS_CANCELLED,
            self::STATUS_REFUNDED        => 'annullato',
            default                      => 'attesa',
        };
    }

    /**
     * Il numero d'ordine che si cita al venditore o all'assistenza.
     *
     * L'uuid intero e' illeggibile al telefono; le prime otto cifre in
     * maiuscolo si dettano e restano uniche a sufficienza per ritrovare
     * l'ordine (e comunque la ricerca vera si fa sull'uuid completo).
     */
    public function getNumeroAttribute(): string
    {
        return strtoupper(substr((string) $this->uuid, 0, 8));
    }

}
