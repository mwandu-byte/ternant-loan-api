<?php

namespace App\Services\Reports;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\RepaymentSchedule;
use App\Services\Repayment\OverdueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Complete overview of the loan portfolio: status/amount summary plus a
 * paginated list of loans. Paid/outstanding amounts are derived from each
 * loan's repayment_schedules (never recomputed independently), matching
 * how DashboardService already sources total_outstanding_amount.
 */
class LoanPortfolioReportService
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
        $query = Loan::query()->visibleTo(auth()->user());

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // start_date is the loan's own business date (when it was issued),
        // consistent with how DashboardService scopes loan-level metrics —
        // never the row's created_at.
        if (! empty($filters['date_from'])) {
            $query->whereDate('start_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('start_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with('customer')
            ->withSum('repaymentSchedules as scheduled_total', 'total_amount')
            ->withSum('repaymentSchedules as outstanding_sum', 'outstanding_amount')
            ->orderBy('start_date', 'desc');

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
        $statusCounts = $this->baseQuery($filters)
            ->select('status', DB::raw('count(*) as aggregate_count'))
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $totals = $this->baseQuery($filters)
            ->selectRaw('COALESCE(SUM(principal_amount), 0) as principal, COALESCE(SUM(interest_amount), 0) as interest, COALESCE(SUM(total_amount), 0) as total_amount')
            ->first();

        $scheduleTotals = RepaymentSchedule::query()
            ->whereIn('loan_id', $this->baseQuery($filters)->select('id'))
            ->selectRaw('COALESCE(SUM(total_amount - outstanding_amount), 0) as paid, COALESCE(SUM(outstanding_amount), 0) as outstanding')
            ->first();

        $penaltyTotal = Penalty::query()
            ->whereIn('loan_id', $this->baseQuery($filters)->select('id'))
            ->sum('amount');

        $overdueLoans = $this->baseQuery($filters)
            ->whereHas('repaymentSchedules', fn (Builder $query) => $this->overdueService->applyOverdueScope($query))
            ->count();

        return [
            'total_loans' => (int) $statusCounts->sum(),
            'active_loans' => (int) ($statusCounts['active'] ?? 0),
            'pending_loans' => (int) ($statusCounts['pending'] ?? 0),
            'completed_loans' => (int) ($statusCounts['completed'] ?? 0),
            'cancelled_loans' => (int) ($statusCounts['cancelled'] ?? 0),
            'overdue_loans' => $overdueLoans,
            'total_principal' => $this->formatAmount($totals->principal ?? 0),
            'total_interest' => $this->formatAmount($totals->interest ?? 0),
            'total_loan_amount' => $this->formatAmount($totals->total_amount ?? 0),
            'total_paid' => $this->formatAmount($scheduleTotals->paid ?? 0),
            'total_outstanding' => $this->formatAmount($scheduleTotals->outstanding ?? 0),
            'total_penalties' => $this->formatAmount($penaltyTotal),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(function (Loan $loan) {
            $scheduledTotal = (float) ($loan->scheduled_total ?? 0);
            $outstandingSum = (float) ($loan->outstanding_sum ?? 0);

            return [
                'reference_no' => $loan->reference_no,
                'customer' => $loan->customer === null ? null : [
                    'id' => $loan->customer->id,
                    'full_name' => $loan->customer->full_name,
                    'phone' => $loan->customer->phone,
                ],
                'principal_amount' => (string) $loan->principal_amount,
                'interest_rate' => (string) $loan->interest_rate,
                'applied_interest_rate' => (string) $loan->applied_interest_rate,
                'interest_amount' => (string) $loan->interest_amount,
                'total_amount' => (string) $loan->total_amount,
                'paid_amount' => $this->formatAmount($scheduledTotal - $outstandingSum),
                'outstanding_amount' => $this->formatAmount($outstandingSum),
                'repayment_frequency' => $loan->repayment_frequency,
                'repayment_term' => $loan->repayment_term,
                'start_date' => optional($loan->start_date)->toDateString(),
                'due_date' => optional($loan->due_date)->toDateString(),
                'status' => $loan->status,
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
