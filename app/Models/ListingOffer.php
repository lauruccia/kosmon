<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Offerta della settimana" (2026-08-13, richiesta di Laura): un prodotto
 * shop già pubblicato (dall'azienda o da admin per suo conto) può essere
 * messo in offerta per un periodo limitato, con un prezzo scontato e una
 * percentuale KY propria (in genere 100%), indipendenti dal prezzo/mix
 * normali del prodotto (Listing::price_ky/ky_percentage) — che restano
 * quelli "veri" a cui il prodotto torna automaticamente non appena
 * l'offerta scade o viene terminata. Vedi Listing::activeOffer(): nessun
 * job schedulato, il calcolo è sempre a query-time sulla data (stesso
 * ragionamento di Listing::scopeActive()/is_expired).
 *
 * Ogni riga resta come storico anche dopo la scadenza/cancellazione — mai
 * cancellata fisicamente (richiesta esplicita di Laura di poter rivedere
 * le offerte passate).
 *
 * @property int $id
 * @property int $listing_id
 * @property int|null $created_by_user_id
 * @property int $full_price_ky_snapshot
 * @property int $offer_price_ky
 * @property int $offer_ky_percentage
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Listing $listing
 * @property-read \App\Models\User|null $createdByUser
 * @property-read bool $is_active
 * @property-read int $discount_percent
 * @property-read int $ky_amount
 * @property-read int $euro_amount
 * @method static Builder<static>|ListingOffer current()
 * @mixin \Eloquent
 */
class ListingOffer extends Model
{
    protected $fillable = [
        'listing_id',
        'created_by_user_id',
        'full_price_ky_snapshot',
        'offer_price_ky',
        'offer_ky_percentage',
        'expires_at',
        'cancelled_at',
    ];

    protected $casts = [
        'full_price_ky_snapshot' => 'integer',
        'offer_price_ky'         => 'integer',
        'offer_ky_percentage'    => 'integer',
        'expires_at'             => 'datetime',
        'cancelled_at'           => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Offerte "correnti": non terminate a mano e non ancora scadute per data.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->where('expires_at', '>', now());
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->cancelled_at === null && $this->expires_at->isFuture();
    }

    /**
     * Percentuale di sconto rispetto al prezzo pieno fotografato alla
     * creazione dell'offerta (non ricalcolata sul prezzo attuale del
     * prodotto, che potrebbe essere cambiato nel frattempo).
     */
    public function getDiscountPercentAttribute(): int
    {
        if ($this->full_price_ky_snapshot <= 0) {
            return 0;
        }

        return (int) round((1 - ($this->offer_price_ky / $this->full_price_ky_snapshot)) * 100);
    }

    public function getKyAmountAttribute(): int
    {
        return (int) round($this->offer_price_ky * $this->offer_ky_percentage / 100);
    }

    public function getEuroAmountAttribute(): int
    {
        return $this->offer_price_ky - $this->ky_amount;
    }
}
