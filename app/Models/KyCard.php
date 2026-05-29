<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class KyCard extends Model
{
    protected $fillable = [
        'uuid', 'name', 'description',
        'price_eur_cents', 'bonus_type', 'ky_base_amount', 'bonus_value',
        'is_active', 'stripe_price_id', 'sort_order',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'bonus_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (KyCard $card) {
            if (empty($card->uuid)) {
                $card->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relazioni ──────────────────────────────────────────────────────────

    public function purchases(): HasMany
    {
        return $this->hasMany(KyCardPurchase::class);
    }

    // ── Accessor calcolati ─────────────────────────────────────────────────

    /** Prezzo in euro (float) */
    public function getPriceEurAttribute(): float
    {
        return $this->price_eur_cents / 100;
    }

    /**
     * KY totali che il cliente riceve.
     * - fixed:      ky_base_amount + bonus_value (KY extra fissi)
     * - percentage: ky_base_amount + ky_base_amount * bonus_value / 100
     */
    public function getKyTotalAttribute(): int
    {
        if ($this->bonus_type === 'fixed') {
            return (int) ($this->ky_base_amount + $this->bonus_value);
        }
        // percentage
        return (int) round($this->ky_base_amount * (1 + $this->bonus_value / 100));
    }

    /** KY bonus (differenza tra totale e base) */
    public function getKyBonusAttribute(): int
    {
        return $this->ky_total - (int) $this->ky_base_amount;
    }

    /** Etichetta bonus leggibile, es. "+25 KY" o "+25%" */
    public function getBonusLabelAttribute(): string
    {
        if ($this->bonus_type === 'percentage') {
            return '+' . rtrim(rtrim(number_format((float)$this->bonus_value, 2), '0'), '.') . '%';
        }
        return '+' . number_format($this->ky_bonus, 0, ',', '.') . ' KY';
    }

    /** Scope card attive ordinate */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('price_eur_cents');
    }
}
