<?php

namespace Database\Factories;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        [$raw, $hash, $prefix] = ApiToken::generateRaw();

        return [
            'name'         => $this->faker->words(3, true) . ' token',
            'token_hash'   => $hash,
            'token_prefix' => $prefix,
            'abilities'    => ['read'],
            'expires_at'   => null,
        ];
    }
}
