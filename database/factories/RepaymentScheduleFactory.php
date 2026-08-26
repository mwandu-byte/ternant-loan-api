<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RepaymentSchedule>
 */
class RepaymentScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $principal = fake()->randomFloat(2, 5000, 100000);
        $interest = round($principal * 0.10, 2);
        $total = round($principal + $interest, 2);

        return [
            'loan_id' => Loan::factory()->active(),
            'installment_number' => 1,
            'due_date' => Carbon::now()->addMonth()->toDateString(),
            'principal_amount' => $principal,
            'interest_amount' => $interest,
            'total_amount' => $total,
            'outstanding_amount' => $total,
            'status' => 'pending',
        ];
    }

    public function overdue(): static
    {
        // 10 days past due comfortably clears the seeded 7-day default
        // grace period, so this state remains genuinely 'overdue' (not
        // merely 'due') under grace-period-aware status derivation.
        return $this->state(fn (array $attributes) => [
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function dueToday(): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => Carbon::now()->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'outstanding_amount' => 0,
        ]);
    }
}
