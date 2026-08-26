<?php

namespace Tests\Unit\Services;

use App\Models\GracePeriod;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\RepaymentSchedule;
use App\Services\LoanConfiguration\GracePeriodService;
use App\Services\LoanConfiguration\PenaltyRuleService;
use App\Services\Penalty\PenaltyService;
use App\Services\Repayment\OverdueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PenaltyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GracePeriod::query()->first()->update(['duration' => 7, 'unit' => 'days']);
    }

    private function service(): PenaltyService
    {
        return new PenaltyService(new PenaltyRuleService, new OverdueService(new GracePeriodService));
    }

    private function overdueSchedule(float $principal, int $daysPastDue = 10): RepaymentSchedule
    {
        $loan = Loan::factory()->active()->create(['principal_amount' => $principal]);

        return RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'due_date' => Carbon::now()->subDays($daysPastDue)->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_applies_the_configured_fixed_penalty_amount(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99,
            'penalty_type' => 'fixed', 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $schedule = $this->overdueSchedule(500000);

        $penalty = $this->service()->apply($schedule);

        $this->assertNotNull($penalty);
        $this->assertSame('50000.00', (string) $penalty->amount);
    }

    public function test_penalty_amount_reflects_rule_configuration_not_a_hardcoded_value(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99,
            'penalty_type' => 'fixed', 'penalty_value' => 73250.50, 'application_frequency' => 'once',
        ]);

        $penalty = $this->service()->apply($this->overdueSchedule(500000));

        $this->assertSame('73250.50', (string) $penalty->amount);
    }

    public function test_does_not_apply_a_penalty_before_grace_period_expires(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        // Only 3 days past due — within the 7-day grace period.
        $schedule = $this->overdueSchedule(500000, daysPastDue: 3);

        $penalty = $this->service()->apply($schedule);

        $this->assertNull($penalty);
        $this->assertSame(0, Penalty::count());
    }

    public function test_does_not_apply_a_penalty_to_a_fully_paid_schedule(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $loan = Loan::factory()->active()->create(['principal_amount' => 500000]);
        $schedule = RepaymentSchedule::factory()->paid()->create([
            'loan_id' => $loan->id,
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
        ]);

        $penalty = $this->service()->apply($schedule);

        $this->assertNull($penalty);
        $this->assertSame(0, Penalty::count());
    }

    public function test_skips_without_error_when_no_penalty_rule_matches_the_principal(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 100000, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        // Principal falls outside the only configured bracket.
        $penalty = $this->service()->apply($this->overdueSchedule(5000000));

        $this->assertNull($penalty);
        $this->assertSame(0, Penalty::count());
    }

    public function test_once_frequency_applies_exactly_one_penalty_across_repeated_calls(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $schedule = $this->overdueSchedule(500000);
        $service = $this->service();

        $first = $service->apply($schedule);
        $second = $service->apply($schedule);
        $third = $service->apply($schedule);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($third);
        $this->assertSame(1, Penalty::where('repayment_schedule_id', $schedule->id)->count());
    }

    public function test_penalty_does_not_modify_loan_principal(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $schedule = $this->overdueSchedule(500000);
        $loan = $schedule->loan;
        $originalPrincipal = (string) $loan->principal_amount;

        $this->service()->apply($schedule);

        $this->assertSame($originalPrincipal, (string) $loan->refresh()->principal_amount);
    }

    public function test_penalty_does_not_modify_repayment_schedule_total_or_outstanding(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 50000, 'application_frequency' => 'once',
        ]);

        $schedule = $this->overdueSchedule(500000);
        $originalTotal = (string) $schedule->total_amount;
        $originalOutstanding = (string) $schedule->outstanding_amount;

        $this->service()->apply($schedule);

        $schedule->refresh();
        $this->assertSame($originalTotal, (string) $schedule->total_amount);
        $this->assertSame($originalOutstanding, (string) $schedule->outstanding_amount);
    }

    public function test_daily_frequency_applies_one_penalty_per_distinct_day(): void
    {
        PenaltyRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 2999999.99, 'penalty_value' => 1000, 'application_frequency' => 'daily',
        ]);

        $schedule = $this->overdueSchedule(500000);
        $service = $this->service();

        $first = $service->apply($schedule);
        $second = $service->apply($schedule); // Same day — must not duplicate.

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Penalty::where('repayment_schedule_id', $schedule->id)->count());
    }
}
