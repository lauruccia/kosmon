<?php

namespace App\Models;

use App\Services\ImageResizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $created_by_user_id
 * @property string $title
 * @property string $description
 * @property string $category
 * @property string|null $subcategory
 * @property int $price_ky
 * @property array<array-key, mixed>|null $images
 * @property string $status
 * @property bool $featured
 * @property string|null $contact_info
 * @property string|null $delivery_note
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $views_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $ky_percentage
 * @property int|null $desired_ky_percentage
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $createdByUser
 * @property-read string $category_label
 * @property-read string|null $subcategory_label
 * @property-read int $euro_amount
 * @property-read string|null $first_image
 * @property-read string|null $first_image_url
 * @property-read array $image_urls
 * @property-read bool $is_expired
 * @property-read int $ky_amount
 * @property-read string $ky_badge_color
 * @property-read string $ky_badge_label
 * @method static Builder<static>|Listing active()
 * @method static Builder<static>|Listing featured()
 * @method static Builder<static>|Listing inCategory(string $category)
 * @method static Builder<static>|Listing newModelQuery()
 * @method static Builder<static>|Listing newQuery()
 * @method static Builder<static>|Listing query()
 * @method static Builder<static>|Listing whereCategory($value)
 * @method static Builder<static>|Listing whereCompanyId($value)
 * @method static Builder<static>|Listing whereContactInfo($value)
 * @method static Builder<static>|Listing whereCreatedAt($value)
 * @method static Builder<static>|Listing whereCreatedByUserId($value)
 * @method static Builder<static>|Listing whereDeliveryNote($value)
 * @method static Builder<static>|Listing whereDescription($value)
 * @method static Builder<static>|Listing whereExpiresAt($value)
 * @method static Builder<static>|Listing whereFeatured($value)
 * @method static Builder<static>|Listing whereId($value)
 * @method static Builder<static>|Listing whereImages($value)
 * @method static Builder<static>|Listing whereKyPercentage($value)
 * @method static Builder<static>|Listing wherePriceKy($value)
 * @method static Builder<static>|Listing whereStatus($value)
 * @method static Builder<static>|Listing whereTitle($value)
 * @method static Builder<static>|Listing whereUpdatedAt($value)
 * @method static Builder<static>|Listing whereUuid($value)
 * @method static Builder<static>|Listing whereViewsCount($value)
 * @mixin \Eloquent
 */
class Listing extends Model
{
    use HasFactory;

    // Le categorie NON sono più hardcoded qui (rimosse il 2026-08-12, richiesta
    // di Laura): vivono nella tabella listing_categories, gestibili da
    // Admin -> Shop -> Categorie (vedi ListingCategory, AdminListingCategoryController).

    public const STATUSES = ['active', 'suspended', 'expired', 'draft'];

    /** Etichette in italiano degli stati (l'interfaccia è sempre in italiano, mai i valori grezzi via ucfirst()). */
    public const STATUS_LABELS = [
        'active'    => 'Attivo',
        'suspended' => 'Sospeso',
        'expired'   => 'Scaduto',
        'draft'     => 'Bozza',
    ];

    /**
     * Etichetta italiana leggibile per uno stato (usare al posto di ucfirst($status)).
     */
    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst($status);
    }

    /**
     * Valori consentiti per il mix KY/EUR.
     * 12/08/2026: rimosso 0 (100% EUR) su richiesta di Laura — non serve,
     * un prodotto shop deve avere sempre una quota KY minima del 25%.
     */
    public const KY_PERCENTAGES = [25, 50, 75, 100];

    /**
     * Tipo di consegna/erogazione del prodotto (2026-07-29, richiesta di
     * Laura). Solo 'spedizione' richiede un indirizzo di spedizione dal
     * cliente (vedi Account::hasShippingAddress()) e ammette un costo di
     * spedizione facoltativo (shipping_cost).
     */
    public const DELIVERY_TYPE_SPEDIZIONE = 'spedizione';
    public const DELIVERY_TYPE_RITIRO     = 'ritiro';
    public const DELIVERY_TYPE_SERVIZIO   = 'servizio';

    public const DELIVERY_TYPES = [
        self::DELIVERY_TYPE_SPEDIZIONE => 'Prodotto fisico da spedire',
        self::DELIVERY_TYPE_RITIRO     => 'Ritiro in sede',
        self::DELIVERY_TYPE_SERVIZIO   => 'Servizio (online o in sede)',
    ];

    protected $fillable = [
        'uuid',
        'company_id',
        'created_by_user_id',
        'title',
        'description',
        'category',
        'subcategory',
        'price_ky',
        'ky_percentage',
        'desired_ky_percentage',
        'stock_quantity',
        'has_variants',
        'images',
        'status',
        'featured',
        'contact_info',
        'delivery_note',
        'delivery_type',
        'shipping_cost',
        'expires_at',
        'views_count',
    ];

    protected $casts = [
        'has_variants' => 'boolean',
        'images'     => 'array',
        'featured'   => 'boolean',
        'expires_at' => 'datetime',
        'price_ky'      => 'integer',
        'ky_percentage' => 'integer',
        'desired_ky_percentage' => 'integer',
        'stock_quantity' => 'integer',
        'views_count' => 'integer',
        'shipping_cost' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            $listing->uuid ??= (string) Str::uuid();

            // 13/08/2026: "percentuale desiderata" (vedi Account::syncListingsKyPercentage())
            // usata per ripristinare il mix scelto dal negozio quando il conto
            // torna fuori dal debito. Se non è stata impostata esplicitamente
            // (es. Factory/seeder, o creazione fuori dal form standard) parte
            // allineata alla percentuale effettiva, così non resta mai NULL.
            $listing->desired_ky_percentage ??= $listing->ky_percentage;
        });
    }

    // ── Relazioni ─────────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Ordini (transfer di tipo portal_marketplace_order) generati dall'acquisto
     * di questo prodotto tramite il bottone "Acquista" dello shop.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Transfer::class, 'listing_id');
    }

    /**
     * Storico completo delle offerte "della settimana" di questo prodotto
     * (attive, terminate manualmente e scadute — vedi ListingOffer).
     */
    public function offers(): HasMany
    {
        return $this->hasMany(ListingOffer::class);
    }

    /**
     * Offerta "della settimana" attualmente in corso per questo prodotto, se
     * presente (2026-08-13). Nessun job schedulato: quando expires_at passa,
     * questa relazione smette semplicemente di ritornare risultati e il
     * prodotto torna in automatico al prezzo/percentuale normali ovunque
     * venga mostrato o acquistato — vedi gli accessor effective_* sotto, da
     * usare SEMPRE al posto di price_ky/ky_percentage nelle view e
     * nell'acquisto.
     */
    public function activeOffer(): HasOne
    {
        return $this->hasOne(ListingOffer::class)
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->latestOfMany();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                     })
                     // Un'azienda sospesa esce dal commercio (decisione di Laura,
                     // 26/08/2026): i suoi prodotti spariscono dal catalogo,
                     // dalle offerte e dalle fasce "in evidenza" senza doverli
                     // sospendere uno per uno - e senza toccarne lo `status`,
                     // cosi' quando la sospensione viene revocata tornano su
                     // esattamente com'erano.
                     ->whereHas('company', fn ($c) => $c->whereNull('suspended_at'));
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    /**
     * Prodotti con un'offerta "della settimana" attualmente in corso — usato
     * dalla pagina pubblica /shop/offerte (ListingController::offers()).
     */
    public function scopeOnOffer(Builder $query): Builder
    {
        return $query->whereHas('offers', function (Builder $q) {
            $q->whereNull('cancelled_at')->where('expires_at', '>', now());
        });
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeInSubcategory(Builder $query, string $subcategory): Builder
    {
        return $query->where('subcategory', $subcategory);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Etichetta leggibile della categoria, presa da listing_categories (con
     * fallback al valore grezzo se la categoria è stata nel frattempo
     * eliminata — vedi ListingCategory::labelFor()).
     */
    public function getCategoryLabelAttribute(): string
    {
        return ListingCategory::labelFor($this->category) ?? $this->category;
    }

    /**
     * Etichetta leggibile della sotto-categoria, o null se il prodotto non ne
     * ha una assegnata (è facoltativa).
     */
    public function getSubcategoryLabelAttribute(): ?string
    {
        return ListingCategory::labelFor($this->subcategory);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function getFirstImageAttribute(): ?string
    {
        return $this->images[0] ?? null;
    }

    /**
     * URL pubblici di tutte le immagini (per le view).
     * Ritorna un array vuoto se non ci sono immagini.
     */
    public function getImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->all();
    }

    /**
     * URL della prima immagine, o null. E' l'ORIGINALE, a piena risoluzione:
     * usalo solo dove serve davvero grande (la lente d'ingrandimento).
     */
    public function getFirstImageUrlAttribute(): ?string
    {
        $path = $this->images[0] ?? null;
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * URL di una versione ridotta, con ricaduta sull'originale.
     *
     * La ricaduta e' il cuore della cosa (27/08/2026): le miniature possono
     * mancare per tre motivi legittimi — la foto e' stata caricata prima che
     * esistesse questo meccanismo, era gia' piu' piccola della misura chiesta,
     * oppure GD non e' riuscito a generarla. In tutti e tre i casi la pagina
     * deve mostrare la foto lo stesso. Una card vuota sarebbe un guasto; una
     * card lenta e' solo lenta.
     */
    public function urlRidotto(?string $path, string $misura): ?string
    {
        if (! $path) {
            return null;
        }

        $disco    = Storage::disk('public');
        $derivato = ImageResizer::pathDerivato($path, $misura);

        return $disco->exists($derivato)
            ? $disco->url($derivato)
            : $disco->url($path);
    }

    /**
     * L'immagine per le GRIGLIE: shop, "I miei prodotti", carrello, offerte.
     * Un sesto del peso dell'originale, e nella card non si vede differenza.
     */
    public function getCardImageUrlAttribute(): ?string
    {
        return $this->urlRidotto($this->images[0] ?? null, ImageResizer::CARD);
    }

    /**
     * Le immagini della scheda prodotto, nella misura media.
     *
     * @return array<int, string>
     */
    public function getMediumImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])
            ->map(fn (string $path) => $this->urlRidotto($path, ImageResizer::MEDIUM))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Le stesse immagini in misura card: la striscia sotto la foto grande.
     *
     * @return array<int, string>
     */
    public function getCardImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])
            ->map(fn (string $path) => $this->urlRidotto($path, ImageResizer::CARD))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Elimina una singola immagine dal disco e dall'array.
     * Ritorna true se l'immagine è stata trovata e rimossa.
     */
    public function deleteImage(string $path): bool
    {
        $images = $this->images ?? [];
        if (! in_array($path, $images, true)) {
            return false;
        }
        Storage::disk('public')->delete($path);

        // Anche le versioni ridotte, altrimenti restano sul disco per sempre:
        // nessuna pagina le mostra piu' e nessuno si accorge che ci sono.
        app(ImageResizer::class)->eliminaDerivate($path);

        $this->images = array_values(array_filter($images, fn ($p) => $p !== $path));
        $this->save();
        return true;
    }

    /**
     * Elimina tutte le immagini dal disco.
     */
    public function deleteAllImages(): void
    {
        foreach ($this->images ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }
        // Rimuovi anche la cartella se vuota
        Storage::disk('public')->deleteDirectory("listings/{$this->uuid}");
    }

    // ---- Stock/quantità -----------------------------------------------------

    /**
     * true se il prodotto ha una scorta limitata (stock_quantity valorizzato).
     * NULL = stock illimitato (comportamento storico, nessun limite).
     */
    public function hasLimitedStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    // ── Prodotti variabili (fase D, 25/08/2026) ──────────────────────────────

    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Solo le combinazioni che il venditore ha lasciato accese. */
    public function variantiAttive(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    /**
     * È un prodotto variabile? Serve l'interruttore E almeno una combinazione:
     * un prodotto marcato variabile ma senza varianti non sarebbe comprabile
     * in nessun modo, e allora tanto vale trattarlo come semplice.
     */
    public function isVariabile(): bool
    {
        if (! $this->has_variants) {
            return false;
        }

        $varianti = $this->relationLoaded('variantiAttive')
            ? $this->variantiAttive
            : $this->variantiAttive()->get();

        return $varianti->isNotEmpty();
    }

    /**
     * Scorte complessive di un prodotto variabile: la somma delle sue
     * combinazioni, o null se almeno una è illimitata.
     */
    public function scorteVarianti(): ?int
    {
        $varianti = $this->relationLoaded('variantiAttive')
            ? $this->variantiAttive
            : $this->variantiAttive()->get();

        if ($varianti->contains(fn (ListingVariant $v) => ! $v->hasLimitedStock())) {
            return null;
        }

        return (int) $varianti->sum('stock_quantity');
    }

    /**
     * true se il prodotto può essere acquistato adesso (illimitato, o scorta > 0).
     */
    public function isInStock(): bool
    {
        return ! $this->hasLimitedStock() || $this->stock_quantity > 0;
    }

    /**
     * Etichetta leggibile per la disponibilità, per le view.
     */
    public function getStockLabelAttribute(): string
    {
        if (! $this->hasLimitedStock()) {
            return 'Disponibile';
        }
        if ($this->stock_quantity <= 0) {
            return 'Esaurito';
        }
        return $this->stock_quantity === 1 ? 'Ultimo pezzo disponibile' : "{$this->stock_quantity} disponibili";
    }

    // ---- Mix KY/EUR --------------------------------------------------------

    /**
     * Quota prezzo in KY (la parte che transita nel circuito).
     */
    public function getKyAmountAttribute(): int
    {
        return (int) round($this->price_ky * $this->ky_percentage / 100);
    }

    /**
     * Quota prezzo in euro (pagata off-circuit tra acquirente e venditore).
     */
    public function getEuroAmountAttribute(): int
    {
        return $this->price_ky - $this->ky_amount;
    }

    /**
     * Etichetta leggibile del mix, es. "75% KY + 25% EUR".
     */
    public function getKyBadgeLabelAttribute(): string
    {
        if ($this->ky_percentage === 100) {
            return '100% KY';
        }
        if ($this->ky_percentage === 0) {
            return '100% EUR';
        }
        $eur = 100 - $this->ky_percentage;
        return "{$this->ky_percentage}% KY + {$eur}% EUR";
    }

    /**
     * CSS inline (background/color) del badge mix KY/EUR, per le view.
     *
     * NB: prima ritornava classi Tailwind (es. "bg-emerald-100 text-emerald-800")
     * ma le view lo iniettavano dentro un attributo style="" — il browser
     * scartava quella dichiarazione perché non è CSS valido, quindi il badge
     * restava sempre bianco/neutro. Tailwind inoltre non avrebbe comunque
     * generato quelle utility class, perché lo scanner (@source in app.css)
     * copre solo resources/**\/*.blade.php e *.js, non i file PHP dei Model.
     * Ritornando dichiarazioni CSS dirette il badge funziona in ogni caso.
     */
    public function getKyBadgeColorAttribute(): string
    {
        return match(true) {
            $this->ky_percentage === 100 => 'background:#d1fae5;color:#065f46;',
            $this->ky_percentage >= 50   => 'background:#dbeafe;color:#1e40af;',
            $this->ky_percentage > 0     => 'background:#fef3c7;color:#92400e;',
            default                      => 'background:#f1f5f9;color:#475569;',
        };
    }

    // ---- Offerta della settimana ---------------------------------------------

    /**
     * true se questo prodotto ha un'offerta "della settimana" attualmente in
     * corso — vedi activeOffer().
     */
    public function getIsOnOfferAttribute(): bool
    {
        return $this->activeOffer !== null;
    }

    /**
     * Prezzo da mostrare/addebitare ADESSO: quello dell'offerta attiva se
     * presente, altrimenti il prezzo pieno normale. Usare SEMPRE questo (e i
     * fratelli sotto) al posto di price_ky/ky_percentage nelle view e
     * nell'acquisto (ListingController::buy()), così un'offerta attiva si
     * riflette automaticamente ovunque il prodotto viene mostrato o pagato,
     * senza dover duplicare la logica "se c'è un'offerta...".
     */
    public function getEffectivePriceKyAttribute(): int
    {
        return $this->activeOffer?->offer_price_ky ?? $this->price_ky;
    }

    public function getEffectiveKyPercentageAttribute(): int
    {
        return $this->activeOffer?->offer_ky_percentage ?? $this->ky_percentage;
    }

    public function getEffectiveKyAmountAttribute(): int
    {
        return (int) round($this->effective_price_ky * $this->effective_ky_percentage / 100);
    }

    public function getEffectiveEuroAmountAttribute(): int
    {
        return $this->effective_price_ky - $this->effective_ky_amount;
    }

    /**
     * Percentuale di sconto dell'offerta attiva, o null se non in offerta —
     * per le view (badge "-20%" ecc.).
     */
    public function getOfferDiscountPercentAttribute(): ?int
    {
        return $this->activeOffer?->discount_percent;
    }

    /**
     * Equivalenti "effettivi" di ky_badge_label/ky_badge_color sopra, basati
     * su effective_ky_percentage invece che sul ky_percentage "di listino" —
     * da usare nelle view al posto degli originali ovunque si mostri il
     * prezzo pagabile ora (che con un'offerta attiva può avere un mix
     * KY/EUR diverso da quello normale del prodotto).
     */
    public function getEffectiveKyBadgeLabelAttribute(): string
    {
        $pct = $this->effective_ky_percentage;
        if ($pct === 100) {
            return '100% KY';
        }
        if ($pct === 0) {
            return '100% EUR';
        }
        return "{$pct}% KY + " . (100 - $pct) . '% EUR';
    }

    public function getEffectiveKyBadgeColorAttribute(): string
    {
        $pct = $this->effective_ky_percentage;
        return match(true) {
            $pct === 100 => 'background:#d1fae5;color:#065f46;',
            $pct >= 50   => 'background:#dbeafe;color:#1e40af;',
            $pct > 0     => 'background:#fef3c7;color:#92400e;',
            default      => 'background:#f1f5f9;color:#475569;',
        };
    }

    // ---- Tipo di consegna / spedizione --------------------------------------

    /**
     * Etichetta leggibile del tipo di consegna (fallback al valore grezzo se
     * il DB contiene qualcosa fuori da DELIVERY_TYPES, per non rompere la view).
     */
    public function getDeliveryTypeLabelAttribute(): string
    {
        return self::DELIVERY_TYPES[$this->delivery_type] ?? ucfirst((string) $this->delivery_type);
    }

    /**
     * Solo i prodotti fisici "da spedire" richiedono un indirizzo di
     * spedizione del cliente — ritiro in sede e servizi (online o in sede)
     * non ne hanno bisogno.
     */
    public function requiresShippingAddress(): bool
    {
        return $this->delivery_type === self::DELIVERY_TYPE_SPEDIZIONE;
    }

    /**
     * Quota KY del costo di spedizione (per unità), con la STESSA percentuale
     * di mix KY/EUR del prodotto — scelta esplicita di Laura (2026-07-29): il
     * costo di spedizione non è "sempre KY" né "sempre EUR", segue il mix del
     * prodotto a cui è collegato.
     */
    public function getShippingKyAmountAttribute(): int
    {
        if (! $this->shipping_cost) {
            return 0;
        }
        return (int) round($this->shipping_cost * $this->ky_percentage / 100);
    }

    /**
     * Quota EUR del costo di spedizione (per unità), complementare a
     * shipping_ky_amount.
     */
    public function getShippingEuroAmountAttribute(): int
    {
        if (! $this->shipping_cost) {
            return 0;
        }
        return $this->shipping_cost - $this->shipping_ky_amount;
    }

}
