<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piano di abbonamento del circuito (Ecommerce, Vetrina, Biglietto, Anagrafica
 * di default — l'admin puo' crearne altri liberamente da /admin/piani e
 * definire quali caratteristiche ha ciascuno).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $price_cents
 * @property bool $can_sell_products
 * @property string $card_style
 * @property int $display_order
 * @property string|null $badge_color
 * @property bool $allow_ky_payment
 * @property bool $is_active
 */
class Plan extends Model
{
    use HasFactory;

    public const CARD_STYLES = [
        'rich'    => 'Ricca (logo, banner, badge premium)',
        'compact' => 'Compatta (logo/iniziale, contatti essenziali)',
        'simple'  => 'Minimale (senza logo)',
    ];

    protected $fillable = [
        'slug', 'name', 'description', 'price_cents',
        'can_sell_products', 'card_style', 'display_order',
        'badge_color', 'allow_ky_payment', 'is_active',
    ];

    protected $casts = [
        'price_cents'       => 'integer',
        'can_sell_products' => 'boolean',
        'display_order'     => 'integer',
        'allow_ky_payment'  => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PlanPayment::class, 'to_plan_id');
    }

    /** Colore badge con fallback deterministico se l'admin non ne ha impostato uno. */
    public function getEffectiveBadgeColorAttribute(): string
    {
        if ($this->badge_color) {
            return $this->badge_color;
        }

        $palette = ['#1d4ed8', '#065f46', '#6b21a8', '#b45309', '#374151', '#0e7490', '#9d174d'];
        return $palette[$this->id % count($palette)];
    }

    protected static function booted(): void
    {
        static::creating(function (Plan $plan): void {
            if (blank($plan->slug)) {
                $plan->slug = \Illuminate\Support\Str::slug($plan->name);
            }
        });
    }
}
