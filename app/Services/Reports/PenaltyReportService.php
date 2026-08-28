<?php

namespace App\Services\Reports;

use App\Models\Penalty;
use App\Services\Repayment\OverdueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penalties charged against overdue installments, sourced entirely from
 * the `penalties` table (never recalculated — accrual belongs to
 * PenaltyService/PenaltyRuleService, not this report).
 *
 * KNOWN DATA LIMITATION: nothing in the schema ever marks a penalty as
 * paid — Repayment has no penalty_id column, and Penalty.status is only
 * ever set to 'applied' by PenaltyService::apply(). This report therefore
 * exposes only `accrued_amount` and the real `status` column; it
 * deliberately does NOT report a "paid"/"outstanding" split, since no such
 * data is actually tracked and fabricating one (e.g. defaulting to 0/full
 * amount) would misrepresent untracked data as real.
 */
class PenaltyReportService
{
    public function __construct(
        private readonly OverdueService $overdueService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(array $filters): array
    {
        $paginator = $this->paginate($filters);

        return [
            'summary' => $this->summarize($filters),
            'items' => $this->transform($paginator),
            'pagination' => $this->paginationMeta($paginator),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Penalty::query();

        if (! empty($filters['loan_id'])) {
            $query->where('loan_id', $filters['loan_id']);
        }

        if (! empty($filters['repayment_schedule_id'])) {
            $query->where('repayment_schedule_id', $filters['repayment_schedule_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->whereHas('loan', fn (Builder $q) => $q->where('customer_id', $filters['customer_id']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('applied_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('applied_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with(['loan.customer', 'repaymentSchedule', 'penaltyRule'])
            ->orderBy('applied_date', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summarize(array $filters): array
    {
        $totals = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(amount), 0) as total_accrued')
            ->first();

        $penalizedLoans = (clone $this->baseQuery($filters))
            ->distinct('loan_id')
            ->count('loan_id');

        return [
            'total_penalties_accrued' => $this->formatAmount($totals->total_accrued ?? 0),
            'number_of_penalized_loans' => $penalizedLoans,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(function (Penalty $penalty) {
            $schedule = $penalty->repaymentSchedule;

            return [
                'loan_reference' => $penalty->loan?->reference_no,
                'customer' => $penalty->loan?->customer === null ? null : [
                    'id' => $penalty->loan->customer->id,
                    'full_name' => $penalty->loan->customer->full_name,
                    'phone' => $penalty->loan->customer->phone,
                ],
                'installment_number' => $schedule?->installment_number,
                'due_date' => optional($schedule?->due_date)->toDateString(),
                // Current overdue days as of today, per OverdueService — not
                // a historical snapshot of how overdue the installment was
                // at the moment the penalty was actually applied, since
                // that isn't stored anywhere.
                'overdue_days' => $schedule === null ? null : $this->overdueService->daysOverdue($schedule),
                'penalty_accrued' => (string) $penalty->amount,
                'penalty_status' => $penalty->status,
                'accrual_date' => optional($penalty->applied_date)->toDateString(),
            ];
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function formatAmount(mixed $sum): string
    {
        return number_format((float) $sum, 2, '.', '');
    }
}
