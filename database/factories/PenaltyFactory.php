<?php

namespace Database\Factories;

use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\RepaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Penalty>
 */
class PenaltyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repayment_schedule_id' => RepaymentSchedule::factory(),
            'penalty_rule_id' => PenaltyRule::factory(),
            'amount' => 50000.00,
            'period_start_date' => Carbon::today()->toDateString(),
            'applied_date' => Carbon::today()->toDateString(),
            'status' => 'applied',
            'reason' => 'Overdue installment beyond grace period.',
        ];
    }

    /**
     * Keep the denormalized loan_id consistent with the schedule's loan
     * unless the caller explicitly overrides loan_id themselves.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Penalty $penalty) {
            if ($penalty->loan_id === null) {
                $penalty->loan_id = RepaymentSchedule::find($penalty->repayment_schedule_id)?->loan_id;
            }
        });
    }
}
