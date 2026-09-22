<?php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
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
