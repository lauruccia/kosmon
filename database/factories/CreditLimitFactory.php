<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CreditLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLimit>
 */
class CreditLimitFactory extends Factory
{
    protected $model = CreditLimit::class;

    public function definition(): array
    {
        return [
            'account_id'            => Account::factory(),
            'credit_limit'          => 50_000,
            'daily_outgoing_limit'  => 20_000,
            'single_transfer_limit' => 10_000,
            'status'                => 'active',
        ];
    }

    public function forAccount(int $accountId): static
    {
        return $this->state(['account_id' => $accountId]);
    }

    public function withLimit(int $creditLimit): static
    {
        return $this->state([
            'credit_limit'          => $creditLimit,
            'daily_outgoing_limit'  => $creditLimit,
            'single_transfer_limit' => $creditLimit,
        ]);
    }
}
