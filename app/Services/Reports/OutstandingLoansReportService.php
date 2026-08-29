<?php

namespace App\Services\Reports;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Loans that currently carry an outstanding balance. Only the single true
 * outstanding_amount is reported — the schema never records how a
 * remaining balance splits between principal and interest (only the
 * ORIGINAL installment split is stored), so no such split is fabricated
 * here.
 */
class OutstandingLoansReportService
{
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
        $query = Loan::query()->visibleTo(auth()->user())->whereHas('repaymentSchedules', function (Builder $q) use ($filters) {
            $q->where('outstanding_amount', '>', 0);

            if (! empty($filters['date_from'])) {
                $q->whereDate('due_date', '>=', $filters['date_from']);
            }

            if (! empty($filters['date_to'])) {
                $q->whereDate('due_date', '<=', $filters['date_to']);
            }
        });

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // Completed/cancelled loans have no meaningful outstanding balance
        // and are excluded unless a status filter explicitly asks for one.
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->whereNotIn('status', ['completed', 'cancelled']);
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
            ->withMin(['repaymentSchedules as next_due_date' => fn (Builder $q) => $q->where('outstanding_amount', '>', 0)], 'due_date')
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
        $loanIds = $this->baseQuery($filters)->select('id');

        $totals = RepaymentSchedule::query()
            ->whereIn('loan_id', $loanIds)
            ->where('outstanding_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(outstanding_amount), 0) as total_outstanding')
            ->first();

        return [
            'total_outstanding' => $this->formatAmount($totals->total_outstanding ?? 0),
            'number_of_outstanding_loans' => (clone $this->baseQuery($filters))->count(),
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
                'total_amount' => (string) $loan->total_amount,
                'paid_amount' => $this->formatAmount($scheduledTotal - $outstandingSum),
                'outstanding_amount' => $this->formatAmount($outstandingSum),
                'next_due_date' => $loan->next_due_date,
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
