<?php

namespace App\Services\Penalty;

use App\Exceptions\Penalty\PenaltyNotFoundException;
use App\Models\Penalty;
use App\Models\PenaltyRule;
use App\Models\RepaymentSchedule;
use App\Services\LoanConfiguration\PenaltyRuleService;
use App\Services\Repayment\OverdueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PenaltyService
{
    public function __construct(
        private readonly PenaltyRuleService $penaltyRuleService,
        private readonly OverdueService $overdueService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Penalty::query()->visibleTo(auth()->user())->with(['loan', 'repaymentSchedule', 'penaltyRule']);

        if (! empty($filters['loan_id'])) {
            $query->where('loan_id', $filters['loan_id']);
        }

        if (! empty($filters['repayment_schedule_id'])) {
            $query->where('repayment_schedule_id', $filters['repayment_schedule_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['applied_date_from'])) {
            $query->whereDate('applied_date', '>=', $filters['applied_date_from']);
        }

        if (! empty($filters['applied_date_to'])) {
            $query->whereDate('applied_date', '<=', $filters['applied_date_to']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(int $id): Penalty
    {
        $penalty = Penalty::with(['loan', 'repaymentSchedule', 'penaltyRule'])->find($id);

        if ($penalty === null) {
            throw new PenaltyNotFoundException;
        }

        return $penalty;
    }

    /**
     * Calculates and applies a penalty to the given schedule if it is
     * eligible: an active PenaltyRule matches the loan's original
     * principal, and no penalty has already been recorded for the
     * relevant accrual period. Returns null (not an exception) when
     * skipped for either reason, so batch callers can distinguish
     * "applied" from "nothing to do" without a try/catch per row.
     */
    public function apply(RepaymentSchedule $schedule): ?Penalty
    {
        // Re-checked here (not just relied upon via the caller's query
        // filter) so this method is safe to call directly against any
        // schedule, not only ones already pre-filtered by
        // OverdueService::applyOverdueScope().
        if (! $this->overdueService->isOverdue($schedule)) {
            return null;
        }

        $loan = $schedule->loan;

        // ORIGINAL loan principal — never outstanding balance, remaining
        // installment amount, or interest — per the approved business rule.
        $rule = $this->penaltyRuleService->resolveApplicableRule((float) $loan->principal_amount, $loan->business_id);

        if ($rule === null) {
            return null;
        }

        $periodStart = $this->resolvePeriodStart($schedule, $rule);

        if ($periodStart === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($schedule, $rule, $periodStart, $loan) {
                // 'once' rules are blocked by any existing row for the
                // schedule at all — not just a match on the freshly
                // recomputed period_start_date — because a grace-period
                // config change between runs could shift what "the"
                // period_start_date computes to today versus what an
                // existing row already stored. Recurring frequencies are
                // scoped to the current period only, so each new period
                // is free to accrue its own charge.
                $alreadyApplied = $rule->application_frequency === 'once'
                    ? Penalty::where('repayment_schedule_id', $schedule->id)->lockForUpdate()->exists()
                    : Penalty::where('repayment_schedule_id', $schedule->id)
                        ->where('period_start_date', $periodStart)
                        ->lockForUpdate()
                        ->exists();

                if ($alreadyApplied) {
                    return null;
                }

                return Penalty::create([
                    'loan_id' => $loan->id,
                    'repayment_schedule_id' => $schedule->id,
                    'penalty_rule_id' => $rule->id,
                    'amount' => $this->calculateAmount($rule),
                    'period_start_date' => $periodStart,
                    'applied_date' => Carbon::today()->toDateString(),
                    'status' => 'applied',
                    'reason' => sprintf(
                        'Overdue installment #%d beyond grace period (expired %s).',
                        $schedule->installment_number,
                        $this->overdueService->gracePeriodExpiresAt($schedule)?->toDateString(),
                    ),
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicatePeriodError($e)) {
                // A concurrent run raced us for the same schedule/period —
                // the unique index caught it. Swallow rather than fail the
                // whole accrual batch over a race we already tolerate.
                return null;
            }

            throw $e;
        }
    }

    private function resolvePeriodStart(RepaymentSchedule $schedule, PenaltyRule $rule): ?string
    {
        return match ($rule->application_frequency) {
            'once' => $this->overdueService->gracePeriodExpiresAt($schedule)?->toDateString(),
            'daily' => Carbon::today()->toDateString(),
            'weekly' => Carbon::today()->startOfWeek()->toDateString(),
            'monthly' => Carbon::today()->startOfMonth()->toDateString(),
            default => Carbon::today()->toDateString(),
        };
    }

    /**
     * penalty_type is currently constrained to 'fixed' by
     * Store/UpdatePenaltyRuleRequest; kept as an explicit match() so a
     * future 'percentage' type is a one-line addition here only.
     */
    private function calculateAmount(PenaltyRule $rule): string
    {
        return match ($rule->penalty_type) {
            default => bcadd((string) $rule->penalty_value, '0', 2),
        };
    }

    private function isDuplicatePeriodError(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'period_start_date');
    }
}
