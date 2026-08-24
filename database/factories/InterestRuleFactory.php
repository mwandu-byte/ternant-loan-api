<?php

namespace Database\Factories;

use App\Models\InterestRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterestRule>
 */
class InterestRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minimum = fake()->randomFloat(2, 0, 100000);

        return [
            'minimum_amount' => $minimum,
            'maximum_amount' => $minimum + fake()->randomFloat(2, 100000, 1000000),
            'interest_rate' => fake()->randomFloat(2, 1, 40),
            'calculation_method' => 'percentage',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the interest rule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
