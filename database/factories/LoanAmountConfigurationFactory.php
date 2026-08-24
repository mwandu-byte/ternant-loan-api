<?php

namespace Database\Factories;

use App\Models\LoanAmountConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanAmountConfiguration>
 */
class LoanAmountConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'minimum_amount' => 0,
            'maximum_amount' => null,
        ];
    }
}
