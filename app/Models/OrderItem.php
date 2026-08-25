<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga di un ordine shop: un prodotto, in una certa quantità, al prezzo
 * che aveva quel giorno.
 *
 * Tutto quello che serve a leggere la riga è COPIATO qui al momento
 * dell'acquisto: titolo, prezzo pieno unitario, mix KY/EUR applicato e i due
 * importi che ne derivano. `listing_id` è un comodo per il link al prodotto,
 * non una dipendenza: se il venditore cancella il prodotto la riga resta
 * leggibile, esattamente come `transfers.order_title` dalla fase 0b.
 *
 * Perché lo snapshot e non un join: il prezzo di un prodotto cambia, va in
 * offerta, l'azienda va in debito e il mix viene forzato al 100% KY. Un ordine
 * chiuso non deve cambiare faccia quando cambia il catalogo.
 *
 * Importi in CENTESIMI.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $listing_id
 * @property string $title
 * @property int $quantity
 * @property int $unit_price_ky
 * @property int $ky_percentage
 * @property int $unit_ky_amount
 * @property int $unit_eur_amount
 * @property int $line_ky_amount
 * @property int $line_eur_amount
 * @property-read Order $order
 * @property-read Listing|null $listing
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'listing_id',
        'listing_variant_id',
        'title',
        'variant_label',
        'quantity',
        'unit_price_ky',
        'ky_percentage',
        'unit_ky_amount',
        'unit_eur_amount',
        'line_ky_amount',
        'line_eur_amount',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'unit_price_ky'   => 'integer',
        'ky_percentage'   => 'integer',
        'unit_ky_amount'  => 'integer',
        'unit_eur_amount' => 'integer',
        'line_ky_amount'  => 'integer',
        'line_eur_amount' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * La combinazione acquistata. Può essere null anche su un ordine con
     * varianti, se nel frattempo il venditore l'ha cancellata: per questo il
     * testo resta congelato in `variant_label`.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ListingVariant::class, 'listing_variant_id');
    }

    /** Titolo completo da mostrare: "Maglione — Taglia: M · rosso". */
    public function getTitoloCompletoAttribute(): string
    {
        return $this->variant_label
            ? $this->title . ' — ' . $this->variant_label
            : $this->title;
    }

    /** Etichetta del mix pagamento di questa riga, come congelato all'acquisto. */
    public function getMixLabelAttribute(): string
    {
        if ($this->ky_percentage >= 100) {
            return '100% KY';
        }
        if ($this->ky_percentage <= 0) {
            return '100% EUR';
        }

        return $this->ky_percentage . '% KY + ' . (100 - $this->ky_percentage) . '% EUR';
    }
}
