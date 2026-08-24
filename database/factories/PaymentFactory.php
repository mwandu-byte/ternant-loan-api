<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory()->active(),
            'amount' => fake()->randomFloat(2, 100000, 3000000),
            'payment_date' => Carbon::now()->toDateString(),
            'payment_method' => 'cash',
            'reference_no' => null,
            'notes' => null,
            'paid_by' => User::factory(),
        ];
    }
}
