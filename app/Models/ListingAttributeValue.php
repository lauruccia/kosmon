<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Un valore possibile di un attributo: "M" per la Taglia, "rosso" per il Colore.
 *
 * Come l'attributo, lo definisce l'admin. Lo `slug` è stabile, il `value` è
 * l'etichetta che si legge — rinominare "rosso" in "Rosso fuoco" non tocca i
 * prodotti che lo usano.
 *
 * @property int $id
 * @property int $listing_attribute_id
 * @property string $slug
 * @property string $value
 * @property bool $is_active
 * @property int $sort_order
 * @property-read ListingAttribute $attribute
 */
class ListingAttributeValue extends Model
{
    protected $fillable = ['listing_attribute_id', 'slug', 'value', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ListingAttributeValue $valore): void {
            if (! $valore->slug) {
                $valore->slug = static::slugLibero($valore->listing_attribute_id, $valore->value);
            }
        });
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ListingAttribute::class, 'listing_attribute_id');
    }

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ListingVariant::class, 'listing_variant_values');
    }

    /** Etichetta completa: "Taglia: M". */
    public function getEtichettaAttribute(): string
    {
        return ($this->attribute?->name ?? 'Attributo') . ': ' . $this->value;
    }

    public static function slugLibero(int $attributeId, string $valore): string
    {
        $base = Str::slug($valore) ?: 'valore';
        $slug = $base;
        $n = 2;

        while (static::query()
            ->where('listing_attribute_id', $attributeId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
