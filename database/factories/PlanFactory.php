<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name'               => ucfirst($name),
            'slug'               => \Illuminate\Support\Str::slug($name) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)),
            'description'        => fake()->sentence(),
            'price_cents'        => fake()->numberBetween(0, 50000),
            'can_sell_products'  => false,
            'card_style'         => 'simple',
            'display_order'      => fake()->numberBetween(0, 10),
            'badge_color'        => null,
            'allow_ky_payment'   => true,
            'is_active'          => true,
        ];
    }
}
