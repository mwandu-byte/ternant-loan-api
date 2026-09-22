<?php

namespace Database\Factories;

use App\Models\Guarantor;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guarantor>
 */
class GuarantorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'full_name' => fake()->name(),
            'phone' => '+255'.fake()->unique()->numerify('6########'),
            'identification_type' => 'NIDA',
            'identification_number' => fake()->unique()->numerify('##########'),
            'address' => fake()->address(),
            'relationship' => fake()->randomElement(['spouse', 'sibling', 'friend', 'colleague']),
        ];
    }
}
