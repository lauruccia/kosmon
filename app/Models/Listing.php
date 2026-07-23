<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property-read \App\Models\Company $company
 * @property-read \App\Models\User $createdByUser
 * @property-read string $category_label
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

    public const CATEGORIES = [
        'alimentari'     => 'Alimentari & Ristorazione',
        'artigianato'    => 'Artigianato & Manifattura',
        'consulenza'     => 'Consulenza & Servizi professionali',
        'formazione'     => 'Formazione & Educazione',
        'informatica'    => 'Informatica & Tecnologia',
        'logistica'      => 'Logistica & Trasporti',
        'marketing'      => 'Marketing & Comunicazione',
        'salute'         => 'Salute & Benessere',
        'turismo'        => 'Turismo & Hospitality',
        'verde'          => 'Verde & Ambiente',
        'altro'          => 'Altro',
    ];

    public const STATUSES = ['active', 'suspended', 'expired', 'draft'];

    /** Valori consentiti per il mix KY/EUR */
    public const KY_PERCENTAGES = [0, 25, 50, 75, 100];

    protected $fillable = [
        'uuid',
        'company_id',
        'created_by_user_id',
        'title',
        'description',
        'category',
        'price_ky',
        'ky_percentage',
        'stock_quantity',
        'images',
        'status',
        'featured',
        'contact_info',
        'delivery_note',
        'expires_at',
        'views_count',
    ];

    protected $casts = [
        'images'     => 'array',
        'featured'   => 'boolean',
        'expires_at' => 'datetime',
        'price_ky'      => 'integer',
        'ky_percentage' => 'integer',
        'stock_quantity' => 'integer',
        'views_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            $listing->uuid ??= (string) Str::uuid();
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

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
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
     * URL della prima immagine, o null.
     */
    public function getFirstImageUrlAttribute(): ?string
    {
        $path = $this->images[0] ?? null;
        return $path ? Storage::disk('public')->url($path) : null;
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
     * Classe CSS Tailwind del badge mix (per le card shop).
     */
    public function getKyBadgeColorAttribute(): string
    {
        return match(true) {
            $this->ky_percentage === 100 => 'bg-emerald-100 text-emerald-800',
            $this->ky_percentage >= 50   => 'bg-blue-100 text-blue-800',
            $this->ky_percentage > 0     => 'bg-amber-100 text-amber-800',
            default                      => 'bg-gray-100 text-gray-700',
        };
    }

}
