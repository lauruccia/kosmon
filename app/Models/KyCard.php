<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $price_eur_cents
 * @property string $bonus_type
 * @property int $ky_base_amount
 * @property numeric $bonus_value
 * @property bool $is_active
 * @property string|null $stripe_price_id
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $bonus_label
 * @property-read int $ky_bonus
 * @property-read int $ky_total
 * @property-read float $price_eur
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\KyCardPurchase> $purchases
 * @property-read int|null $purchases_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard active()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereBonusType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereBonusValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereKyBaseAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard wherePriceEurCents($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereSortOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereStripePriceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KyCard whereUuid($value)
 * @mixin \Eloquent
 */
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
