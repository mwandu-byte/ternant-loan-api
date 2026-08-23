<?php

namespace Database\Factories;

use App\Models\Collateral;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collateral>
 */
class CollateralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => fake()->randomElement(['Vehicle', 'Property', 'Land', 'Equipment', 'Electronics', 'Other']),
            'description' => fake()->sentence(),
            'estimated_value' => fake()->randomFloat(2, 1000, 50000000),
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the collateral is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
