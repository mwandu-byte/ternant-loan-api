<?php

namespace Database\Factories;

use App\Models\Business;
use App\Services\LoanConfiguration\LoanConfigurationProvisioner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Like every real business, a factory business gets its own copy of
     * the loan configuration templates that exist when it is created.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Business $business) => app(LoanConfigurationProvisioner::class)->provisionFor($business));
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'registration_number' => fake()->unique()->numerify('REG-########'),
            'phone' => '+255'.fake()->numerify('7########'),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'status' => 'active',
            'requires_application_fee' => false,
            'requires_guarantor' => false,
        ];
    }

    public function requiringApplicationFee(): static
    {
        return $this->state(fn () => ['requires_application_fee' => true]);
    }

    public function requiringGuarantor(): static
    {
        return $this->state(fn () => ['requires_guarantor' => true]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
