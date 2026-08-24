<?php

namespace Database\Factories;

use App\Models\PenaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PenaltyRule>
 */
class PenaltyRuleFactory extends Factory
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
            'penalty_type' => 'fixed',
            'penalty_value' => fake()->randomFloat(2, 10000, 100000),
            'application_frequency' => 'once',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the penalty rule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
