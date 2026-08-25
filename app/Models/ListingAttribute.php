<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Un attributo dei prodotti variabili: "Taglia", "Colore", "Formato".
 *
 * Lo definisce l'ADMIN, non il venditore (scelta di Laura, 25/08/2026): il
 * venditore sceglie fra i valori che esistono. Stessa impostazione delle
 * categorie shop — un vocabolario comune tiene il catalogo ordinato e rende
 * possibile, un domani, filtrare tutto lo shop per taglia o per colore. Se
 * ognuno scrivesse i propri, avremmo "Taglia", "taglie", "TAGLIA" e "Misura"
 * a indicare la stessa cosa.
 *
 * Lo `slug` è l'identificativo stabile: rinominare "Taglia" in "Misura" dal
 * pannello cambia solo l'etichetta, non rompe nessun prodotto.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $is_active
 * @property int $sort_order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ListingAttributeValue> $values
 */
class ListingAttribute extends Model
{
    protected $fillable = ['slug', 'name', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ListingAttribute $attributo): void {
            if (! $attributo->slug) {
                $attributo->slug = static::slugLibero($attributo->name);
            }
        });
    }

    public function values(): HasMany
    {
        return $this->hasMany(ListingAttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function valoriAttivi(): HasMany
    {
        return $this->values()->where('is_active', true);
    }

    /** Attributi utilizzabili da un venditore, in ordine, coi loro valori. */
    public static function utilizzabili()
    {
        return static::query()
            ->where('is_active', true)
            ->with('valoriAttivi')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ListingAttribute $a) => $a->valoriAttivi->isNotEmpty())
            ->values();
    }

    /**
     * Uno slug che non esista già: "taglia", poi "taglia-2", "taglia-3"...
     */
    public static function slugLibero(string $nome): string
    {
        $base = Str::slug($nome) ?: 'attributo';
        $slug = $base;
        $n = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
