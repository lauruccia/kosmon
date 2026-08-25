<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Una combinazione acquistabile di un prodotto variabile: "Taglia M + rosso".
 *
 * IL PREZZO È UN DELTA rispetto al prodotto, non un prezzo assoluto. Il
 * venditore scrive "22,00" nel form e qui finisce "+2,00" sui 20,00 del
 * prodotto. Il motivo è tutto nelle Offerte della settimana: l'offerta abbassa
 * il prezzo BASE, e con un delta la XL resta "due euro più della base" anche
 * durante l'offerta — il conto si aggiorna da solo. Con i prezzi assoluti
 * avremmo dovuto vietare le offerte sui prodotti variabili, oppure scrivere un
 * secondo motore di prezzi accanto a quello che già esiste.
 *
 * Il MIX KY/EUR resta del prodotto padre, non della variante: e' una decisione
 * commerciale dell'azienda, non un attributo della taglia. Per questo
 * `Account::syncListingsKyPercentage()` — che forza al 100% KY i prodotti di
 * un'azienda in debito — continua a funzionare senza sapere che le varianti
 * esistono.
 *
 * Importi in CENTESIMI.
 *
 * @property int $id
 * @property string $uuid
 * @property int $listing_id
 * @property string|null $sku
 * @property int $price_delta_ky
 * @property int|null $stock_quantity
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Listing $listing
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ListingAttributeValue> $values
 */
class ListingVariant extends Model
{
    protected $fillable = [
        'uuid',
        'listing_id',
        'sku',
        'price_delta_ky',
        'stock_quantity',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_delta_ky' => 'integer',
        'stock_quantity' => 'integer',
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ListingVariant $variante): void {
            if (! $variante->uuid) {
                $variante->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    // ── Relazioni ────────────────────────────────────────────────────────────

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function values(): BelongsToMany
    {
        return $this->belongsToMany(ListingAttributeValue::class, 'listing_variant_values')
            ->with('attribute');
    }

    // ── Prezzo ───────────────────────────────────────────────────────────────

    /**
     * Prezzo pieno di QUESTA combinazione, adesso: il prezzo effettivo del
     * prodotto (offerta compresa) più il delta. Mai sotto zero.
     */
    public function prezzoEffettivo(): int
    {
        $listing = $this->listing;

        return max(0, (int) $listing->effective_price_ky + $this->price_delta_ky);
    }

    /** Quota KY, col mix del prodotto padre. */
    public function quotaKy(): int
    {
        return (int) round($this->prezzoEffettivo() * $this->listing->effective_ky_percentage / 100);
    }

    /** Quota in euro, complementare. */
    public function quotaEuro(): int
    {
        return $this->prezzoEffettivo() - $this->quotaKy();
    }

    // ── Disponibilità ────────────────────────────────────────────────────────

    /** Scorte contate, o infinite (NULL) come per i prodotti semplici. */
    public function hasLimitedStock(): bool
    {
        return $this->stock_quantity !== null;
    }

    public function isDisponibile(int $quantita = 1): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->hasLimitedStock() || $this->stock_quantity >= $quantita;
    }

    // ── Etichette ────────────────────────────────────────────────────────────

    /**
     * "Taglia: M · Colore: rosso" — quello che si legge sulla riga del carrello
     * e che finisce congelato sulla riga dell'ordine.
     */
    public function getEtichettaAttribute(): string
    {
        $valori = $this->relationLoaded('values') ? $this->values : $this->values()->get();

        return $valori
            ->sortBy(fn (ListingAttributeValue $v) => [$v->attribute?->sort_order ?? 0, $v->attribute?->id ?? 0])
            ->map(fn (ListingAttributeValue $v) => $v->etichetta)
            ->implode(' · ');
    }

    /** Etichetta corta senza i nomi degli attributi: "M · rosso". */
    public function getEtichettaCortaAttribute(): string
    {
        $valori = $this->relationLoaded('values') ? $this->values : $this->values()->get();

        return $valori
            ->sortBy(fn (ListingAttributeValue $v) => [$v->attribute?->sort_order ?? 0, $v->attribute?->id ?? 0])
            ->map(fn (ListingAttributeValue $v) => $v->value)
            ->implode(' · ');
    }

    /**
     * Gli id dei valori che compongono questa variante, ordinati: è la chiave
     * con cui si riconosce una combinazione già esistente.
     *
     * @return array<int, int>
     */
    public function chiaveValori(): array
    {
        $valori = $this->relationLoaded('values') ? $this->values : $this->values()->get();

        return $valori->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
