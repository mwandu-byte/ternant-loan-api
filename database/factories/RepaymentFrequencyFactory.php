<?php

namespace Database\Factories;

use App\Models\RepaymentFrequency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepaymentFrequency>
 */
class RepaymentFrequencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->slug(2),
            'interval_value' => fake()->numberBetween(1, 6),
            'interval_unit' => 'month',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the repayment frequency is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
