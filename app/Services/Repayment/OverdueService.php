<?php

namespace App\Services\Repayment;

use App\Models\GracePeriod;
use App\Models\RepaymentSchedule;
use App\Services\LoanConfiguration\GracePeriodService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Single source of truth for grace-period-aware overdue determination.
 *
 * A repayment schedule becomes overdue only after its due date PLUS the
 * configured grace period has elapsed — never from the raw due date
 * alone. Every consumer (the schedule resource, the penalty accrual
 * command, the dashboard) reads through this service so they can never
 * disagree about what counts as overdue.
 */
class OverdueService
{
    private ?GracePeriod $gracePeriod = null;

    public function __construct(
        private readonly GracePeriodService $gracePeriodService,
    ) {
        //
    }

    private function gracePeriod(): GracePeriod
    {
        return $this->gracePeriod ??= $this->gracePeriodService->get();
    }

    /**
     * Schedules due on or before this date have exhausted their grace
     * period as of today.
     */
    public function cutoffDate(): Carbon
    {
        $gracePeriod = $this->gracePeriod();

        return $this->shiftDate(Carbon::today(), -$gracePeriod->duration, $gracePeriod->unit);
    }

    /**
     * The date on which a given schedule's grace period expires
     * (due_date shifted forward by the configured grace period).
     */
    public function gracePeriodExpiresAt(RepaymentSchedule $schedule): ?Carbon
    {
        if ($schedule->due_date === null) {
            return null;
        }

        $gracePeriod = $this->gracePeriod();

        return $this->shiftDate($schedule->due_date->copy(), $gracePeriod->duration, $gracePeriod->unit);
    }

    public function isOverdue(RepaymentSchedule $schedule): bool
    {
        if ($schedule->status === 'paid') {
            return false;
        }

        if (bccomp((string) $schedule->outstanding_amount, '0.00', 2) <= 0) {
            return false;
        }

        $expiresAt = $this->gracePeriodExpiresAt($schedule);

        return $expiresAt !== null && $expiresAt->lte(Carbon::today());
    }

    public function daysOverdue(RepaymentSchedule $schedule): int
    {
        if (! $this->isOverdue($schedule)) {
            return 0;
        }

        return (int) $this->gracePeriodExpiresAt($schedule)->diffInDays(Carbon::today());
    }

    /**
     * Applies the grace-period-aware overdue filter at the query level —
     * the one place the cutoff-date math is expressed as SQL, reused by
     * the penalty accrual batch and the dashboard aggregates so neither
     * has to loop over rows in PHP to determine overdue status.
     *
     * @template TModel of RepaymentSchedule
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyOverdueScope(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', 'paid')
            ->where('outstanding_amount', '>', 0)
            ->where('due_date', '<=', $this->cutoffDate());
    }

    private function shiftDate(Carbon $date, int $amount, string $unit): Carbon
    {
        return match ($unit) {
            'days' => $date->addDays($amount),
            'weeks' => $date->addWeeks($amount),
            'months' => $date->addMonths($amount),
            default => throw new InvalidArgumentException("Unsupported grace period unit: {$unit}"),
        };
    }
}
