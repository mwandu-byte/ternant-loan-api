<?php

namespace App\Services\Repayment;

use App\Models\GracePeriod;
use App\Models\Loan;
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
 *
 * The grace period is configured per business: a schedule is judged by
 * the grace period of its loan's business (the platform default template
 * for a loan with no business).
 */
class OverdueService
{
    /** @var array<string, GracePeriod> keyed by business id ('' = template) */
    private array $gracePeriods = [];

    /** @var array<int, int|null> loan id => business id */
    private array $loanBusinessIds = [];

    public function __construct(
        private readonly GracePeriodService $gracePeriodService,
    ) {
        //
    }

    private function gracePeriod(?int $businessId): GracePeriod
    {
        return $this->gracePeriods[(string) $businessId] ??= $this->gracePeriodService->forBusiness($businessId);
    }

    private function gracePeriodFor(RepaymentSchedule $schedule): GracePeriod
    {
        return $this->gracePeriod($this->businessIdOf($schedule));
    }

    private function businessIdOf(RepaymentSchedule $schedule): ?int
    {
        if ($schedule->relationLoaded('loan')) {
            return $schedule->loan?->business_id;
        }

        if (! array_key_exists($schedule->loan_id, $this->loanBusinessIds)) {
            $this->loanBusinessIds[$schedule->loan_id] = Loan::whereKey($schedule->loan_id)->value('business_id');
        }

        return $this->loanBusinessIds[$schedule->loan_id];
    }

    /**
     * Schedules of the given business due on or before this date have
     * exhausted their grace period as of today.
     */
    public function cutoffDate(?int $businessId = null): Carbon
    {
        return $this->cutoffFor($this->gracePeriod($businessId));
    }

    private function cutoffFor(GracePeriod $gracePeriod): Carbon
    {
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

        $gracePeriod = $this->gracePeriodFor($schedule);

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
     * Each business has its own cutoff. Businesses sharing a grace period
     * share one cutoff, so the query holds one branch per distinct
     * (duration, unit) pair rather than one per business. Loans with no
     * business fall under the platform default template.
     *
     * @template TModel of RepaymentSchedule
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyOverdueScope(Builder $query): Builder
    {
        $groups = GracePeriod::query()->get()
            ->groupBy(fn (GracePeriod $gracePeriod) => $gracePeriod->duration.' '.$gracePeriod->unit);

        return $query
            ->where('status', '!=', 'paid')
            ->where('outstanding_amount', '>', 0)
            ->where(function (Builder $query) use ($groups) {
                // No grace period configured anywhere: nothing can be overdue.
                $query->whereRaw('1 = 0');

                foreach ($groups as $gracePeriods) {
                    $cutoff = $this->cutoffFor($gracePeriods->first());
                    $businessIds = $gracePeriods->pluck('business_id')->filter()->values()->all();
                    $includesTemplate = $gracePeriods->contains(fn (GracePeriod $gracePeriod) => $gracePeriod->business_id === null);

                    $query->orWhere(function (Builder $query) use ($cutoff, $businessIds, $includesTemplate) {
                        $query->where('due_date', '<=', $cutoff)
                            ->whereIn('loan_id', function ($loans) use ($businessIds, $includesTemplate) {
                                $loans->select('id')->from('loans')->where(function ($loans) use ($businessIds, $includesTemplate) {
                                    $loans->whereIn('business_id', $businessIds);

                                    if ($includesTemplate) {
                                        $loans->orWhereNull('business_id');
                                    }
                                });
                            });
                    });
                }
            });
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
