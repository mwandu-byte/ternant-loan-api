<?php

namespace Database\Factories;

use App\Models\ApplicationFee;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationFee>
 */
class ApplicationFeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'amount' => 10000,
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference_no' => fake()->unique()->bothify('AF-########'),
        ];
    }
}
