<?php

namespace Tests\Feature\Penalty;

use App\Models\GracePeriod;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\RepaymentSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PenaltiesAccrueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GracePeriod::query()->first()->update(['duration' => 7, 'unit' => 'days']);
    }

    private function overdueSchedule(float $principal = 500000, int $daysPastDue = 10): RepaymentSchedule
    {
        $loan = Loan::factory()->active()->create(['principal_amount' => $principal]);

        return RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'due_date' => Carbon::now()->subDays($daysPastDue)->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_running_the_command_once_creates_a_penalty_for_an_eligible_schedule(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);
        $schedule = $this->overdueSchedule();

        $this->artisan('penalties:accrue')->assertExitCode(0);

        $this->assertSame(1, Penalty::where('repayment_schedule_id', $schedule->id)->count());
    }

    public function test_running_the_command_twice_does_not_create_duplicate_penalties(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);
        $schedule = $this->overdueSchedule();

        $this->artisan('penalties:accrue')->assertExitCode(0);
        $this->artisan('penalties:accrue')->assertExitCode(0);

        $this->assertSame(1, Penalty::where('repayment_schedule_id', $schedule->id)->count());
    }

    public function test_schedules_still_within_grace_period_are_untouched(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);
        $this->overdueSchedule(daysPastDue: 3);

        $this->artisan('penalties:accrue')->assertExitCode(0);

        $this->assertSame(0, Penalty::count());
    }

    public function test_fully_paid_schedules_are_excluded_from_accrual(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $loan = Loan::factory()->active()->create(['principal_amount' => 500000]);
        RepaymentSchedule::factory()->paid()->create([
            'loan_id' => $loan->id,
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
        ]);

        $this->artisan('penalties:accrue')->assertExitCode(0);

        $this->assertSame(0, Penalty::count());
    }

    public function test_a_schedule_with_no_matching_penalty_rule_is_skipped_without_aborting_the_batch(): void
    {
        // Only covers a low bracket — schedule A's principal falls outside it.
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 100000, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $unmatched = $this->overdueSchedule(principal: 5000000);
        $matched = $this->overdueSchedule(principal: 50000);

        $this->artisan('penalties:accrue')->assertExitCode(0);

        $this->assertSame(0, Penalty::where('repayment_schedule_id', $unmatched->id)->count());
        $this->assertSame(1, Penalty::where('repayment_schedule_id', $matched->id)->count());
    }
}
