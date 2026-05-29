<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    public function definition(): array
    {
        return [
            'initiated_by'    => null,
            'from_account_id' => Account::factory(),
            'to_account_id'   => Account::factory(),
            'amount'          => fake()->numberBetween(100, 10_000),
            'currency_code'   => 'KY',
            'status'          => 'booked',
            'kind'            => 'trade_payment',
            'idempotency_key' => (string) Str::uuid(),
            'description'     => fake()->sentence(),
            'booked_at'       => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'booked_at' => null]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }

    public function between(int $fromAccountId, int $toAccountId): static
    {
        return $this->state([
            'from_account_id' => $fromAccountId,
            'to_account_id'   => $toAccountId,
        ]);
    }

    public function withAmount(int $amount): static
    {
        return $this->state(['amount' => $amount]);
    }

    public function asCollectionRequest(): static
    {
        return $this->state(['kind' => 'portal_collection_request', 'status' => 'pending']);
    }
}
