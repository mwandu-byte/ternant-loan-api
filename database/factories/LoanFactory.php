<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    private static int $sequence = 1;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $principal = fake()->randomFloat(2, 100000, 3000000);
        $rate = $principal < 500000 ? 30.00 : 22.00;
        $interestAmount = round($principal * $rate / 100, 2);
        $totalAmount = round($principal + $interestAmount, 2);
        $startDate = Carbon::now()->subDays(fake()->numberBetween(0, 30));

        return [
            'customer_id' => Customer::factory(),
            'reference_no' => sprintf('LN-%d-%06d', now()->year, self::$sequence++),
            'principal_amount' => $principal,
            'interest_rate' => $rate,
            'interest_amount' => $interestAmount,
            'total_amount' => $totalAmount,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 12,
            'start_date' => $startDate->toDateString(),
            'due_date' => $startDate->copy()->addMonths(12)->toDateString(),
            'status' => 'pending',
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'active']);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'completed']);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'cancelled']);
    }
}
