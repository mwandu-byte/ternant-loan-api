<?php

namespace App\Services\Reports;

use App\Models\Penalty;
use App\Models\RepaymentSchedule;
use App\Services\Repayment\OverdueService;
use App\Support\AccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Overdue repayment schedule installments, determined exclusively via
 * OverdueService::applyOverdueScope() / daysOverdue() — the codebase's
 * single, grace-period-aware source of truth for "overdue". No overdue
 * math is reimplemented here.
 */
class OverdueLoansReportService
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
        $query = AccessScope::restrictViaLoan(
            $this->overdueService->applyOverdueScope(RepaymentSchedule::query()),
            auth()->user(),
        );

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->whereHas('loan', fn (Builder $q) => $q->where('customer_id', $filters['customer_id']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('due_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('due_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with('loan.customer')
            ->withSum('penalties as penalty_amount', 'amount')
            ->orderBy('due_date');

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
        $installmentIds = $this->baseQuery($filters)->select('id');

        $totals = RepaymentSchedule::query()
            ->whereIn('id', $installmentIds)
            ->selectRaw('COUNT(*) as installments_count, COALESCE(SUM(outstanding_amount), 0) as total_overdue')
            ->first();

        $overdueLoansCount = (clone $this->baseQuery($filters))
            ->distinct('loan_id')
            ->count('loan_id');

        $totalPenalties = Penalty::query()
            ->whereIn('repayment_schedule_id', $this->baseQuery($filters)->select('id'))
            ->sum('amount');

        return [
            'overdue_loans_count' => $overdueLoansCount,
            'overdue_installments_count' => (int) ($totals->installments_count ?? 0),
            'total_overdue_amount' => $this->formatAmount($totals->total_overdue ?? 0),
            'total_accrued_penalties' => $this->formatAmount($totalPenalties),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(function (RepaymentSchedule $schedule) {
            $paidAmount = (float) $schedule->total_amount - (float) $schedule->outstanding_amount;

            return [
                'loan_reference' => $schedule->loan?->reference_no,
                'customer' => $schedule->loan?->customer === null ? null : [
                    'id' => $schedule->loan->customer->id,
                    'full_name' => $schedule->loan->customer->full_name,
                    'phone' => $schedule->loan->customer->phone,
                ],
                'installment_number' => $schedule->installment_number,
                'due_date' => optional($schedule->due_date)->toDateString(),
                'expected_amount' => (string) $schedule->total_amount,
                'paid_amount' => $this->formatAmount($paidAmount),
                'outstanding_amount' => (string) $schedule->outstanding_amount,
                'days_overdue' => $this->overdueService->daysOverdue($schedule),
                'penalty_amount' => $schedule->penalty_amount === null ? null : $this->formatAmount($schedule->penalty_amount),
                'status' => $schedule->status,
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
