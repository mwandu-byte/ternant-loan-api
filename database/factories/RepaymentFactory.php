<?php

namespace Database\Factories;

use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Repayment>
 */
class RepaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $schedule = RepaymentSchedule::factory()->create();

        return [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'receipt_id' => Receipt::factory(),
            'amount' => $schedule->total_amount,
            'repayment_date' => Carbon::now()->toDateString(),
            'notes' => null,
            'received_by' => User::factory(),
        ];
    }
}
