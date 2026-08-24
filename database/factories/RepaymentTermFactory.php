<?php

namespace Database\Factories;

use App\Models\RepaymentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepaymentTerm>
 */
class RepaymentTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->numberBetween(1, 24);

        return [
            'name' => "{$value} Months",
            'value' => $value,
            'unit' => 'months',
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the repayment term is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
