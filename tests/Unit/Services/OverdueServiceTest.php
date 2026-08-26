<?php

namespace Tests\Unit\Services;

use App\Models\GracePeriod;
use App\Models\RepaymentSchedule;
use App\Services\LoanConfiguration\GracePeriodService;
use App\Services\Repayment\OverdueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OverdueServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): OverdueService
    {
        return new OverdueService(new GracePeriodService);
    }

    private function setGracePeriod(int $duration, string $unit): void
    {
        GracePeriod::query()->first()->update(['duration' => $duration, 'unit' => $unit]);
    }

    public function test_cutoff_date_shifts_back_by_configured_days(): void
    {
        $this->setGracePeriod(7, 'days');

        $this->assertTrue($this->service()->cutoffDate()->isSameDay(Carbon::today()->subDays(7)));
    }

    public function test_cutoff_date_shifts_back_by_configured_weeks(): void
    {
        $this->setGracePeriod(2, 'weeks');

        $this->assertTrue($this->service()->cutoffDate()->isSameDay(Carbon::today()->subWeeks(2)));
    }

    public function test_cutoff_date_shifts_back_by_configured_months(): void
    {
        $this->setGracePeriod(1, 'months');

        $this->assertTrue($this->service()->cutoffDate()->isSameDay(Carbon::today()->subMonths(1)));
    }

    public function test_grace_period_expires_at_shifts_due_date_forward(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::parse('2026-08-01')->toDateString(),
            'status' => 'pending',
        ]);

        $this->assertTrue(
            $this->service()->gracePeriodExpiresAt($schedule)->isSameDay(Carbon::parse('2026-08-08')),
        );
    }

    public function test_schedule_is_not_overdue_before_grace_period_expires(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
            'status' => 'pending',
        ]);

        $this->assertFalse($this->service()->isOverdue($schedule));
    }

    public function test_schedule_is_overdue_after_grace_period_expires(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);

        $this->assertTrue($this->service()->isOverdue($schedule));
    }

    public function test_fully_paid_schedule_is_never_overdue(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->paid()->create([
            'due_date' => Carbon::now()->subDays(30)->toDateString(),
        ]);

        $this->assertFalse($this->service()->isOverdue($schedule));
    }

    public function test_schedule_with_zero_outstanding_amount_is_never_overdue(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(30)->toDateString(),
            'status' => 'pending',
            'outstanding_amount' => 0,
        ]);

        $this->assertFalse($this->service()->isOverdue($schedule));
    }

    public function test_days_overdue_is_zero_when_not_overdue(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
            'status' => 'pending',
        ]);

        $this->assertSame(0, $this->service()->daysOverdue($schedule));
    }

    public function test_days_overdue_counts_from_grace_period_expiry_not_due_date(): void
    {
        $this->setGracePeriod(7, 'days');

        $schedule = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);

        // due 10 days ago + 7-day grace => expired 3 days ago.
        $this->assertSame(3, $this->service()->daysOverdue($schedule));
    }

    public function test_apply_overdue_scope_matches_only_schedules_past_grace_period(): void
    {
        $this->setGracePeriod(7, 'days');

        $withinGrace = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
            'status' => 'pending',
        ]);
        $pastGrace = RepaymentSchedule::factory()->create([
            'due_date' => Carbon::now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);

        $ids = $this->service()->applyOverdueScope(RepaymentSchedule::query())->pluck('id');

        $this->assertFalse($ids->contains($withinGrace->id));
        $this->assertTrue($ids->contains($pastGrace->id));
    }
}
