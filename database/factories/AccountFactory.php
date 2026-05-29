<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'company_id'            => Company::factory(),
            'owner_type'            => 'company',
            'type'                  => 'primary',
            'currency_code'         => 'KY',
            'status'                => 'active',
            'allow_negative_balance' => true,
            'available_balance'     => 0,
            'pending_balance'       => 0,
        ];
    }

    /**
     * Account with a positive balance (already funded).
     */
    public function withBalance(int $amount): static
    {
        return $this->state(['available_balance' => $amount]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
