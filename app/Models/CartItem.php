<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga del carrello: un prodotto e una quantità. Nient'altro.
 *
 * Nessun prezzo memorizzato, di proposito: i totali si chiedono al catalogo
 * ogni volta, attraverso gli accessor `effective_*` del prodotto. Così
 * un'offerta della settimana che parte (o che scade) mentre il carrello è
 * fermo si riflette da sola, e nessuno paga un prezzo diverso da quello che
 * vede scritto.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $listing_id
 * @property int $quantity
 * @property-read Cart $cart
 * @property-read Listing|null $listing
 */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'listing_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** Quota KY di questa riga, al prezzo di adesso. Spedizione esclusa. */
    public function totaleKy(): int
    {
        return $this->listing ? $this->listing->effective_ky_amount * $this->quantity : 0;
    }

    /** Quota in euro di questa riga, al prezzo di adesso. Spedizione esclusa. */
    public function totaleEuro(): int
    {
        return $this->listing ? $this->listing->effective_euro_amount * $this->quantity : 0;
    }

    /**
     * La riga è ancora acquistabile? Un prodotto può essere stato sospeso,
     * cancellato o esaurito mentre stava nel carrello.
     */
    public function isDisponibile(): bool
    {
        if (! $this->listing || $this->listing->status !== 'active' || $this->listing->is_expired) {
            return false;
        }

        return ! $this->listing->hasLimitedStock() || $this->listing->stock_quantity >= $this->quantity;
    }

    /** Perché non si può comprare, in una frase da mostrare all'utente. */
    public function motivoIndisponibilita(): ?string
    {
        if (! $this->listing) {
            return 'Questo prodotto non è più nel catalogo.';
        }

        if ($this->listing->status !== 'active' || $this->listing->is_expired) {
            return 'Questo prodotto non è più disponibile.';
        }

        if ($this->listing->hasLimitedStock() && $this->listing->stock_quantity < $this->quantity) {
            return $this->listing->stock_quantity <= 0
                ? 'Prodotto esaurito.'
                : "Ne restano solo {$this->listing->stock_quantity}.";
        }

        return null;
    }
}
