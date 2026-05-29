<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name'         => $name,
            'slug'         => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'email'        => fake()->companyEmail(),
            'vat_number'   => null,
            'fiscal_code'  => null,
            'status'       => 'active',
            'kyc_status'   => 'approved',
            'currency_code' => 'KY',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending_review', 'kyc_status' => 'pending']);
    }

    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
