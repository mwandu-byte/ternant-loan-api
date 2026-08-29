<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Services\Repayment\OverdueService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer portfolio overview. Customer has no dedicated business date of
 * its own (unlike Loan's start_date) — date_from/date_to intentionally
 * scope on created_at (registration date), the only real date column
 * available, not a blind default.
 */
class CustomerReportService
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
        $query = Customer::query()->visibleTo(auth()->user());

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->withCount('loans')
            ->withSum('loans as total_borrowed', 'principal_amount')
            ->withSum('repayments as total_repaid', 'amount')
            ->withSum('repaymentSchedules as total_outstanding', 'outstanding_amount')
            ->orderBy('created_at', 'desc');

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
            ->selectRaw('status, count(*) as aggregate_count')
            ->groupBy('status')
            ->pluck('aggregate_count', 'status');

        $withActiveLoans = (clone $this->baseQuery($filters))
            ->whereHas('loans', fn (Builder $q) => $q->where('status', 'active'))
            ->count();

        $withOverdueLoans = (clone $this->baseQuery($filters))
            ->whereHas('loans.repaymentSchedules', fn (Builder $q) => $this->overdueService->applyOverdueScope($q))
            ->count();

        return [
            'total_customers' => (int) $statusCounts->sum(),
            'active_customers' => (int) ($statusCounts['active'] ?? 0),
            'inactive_customers' => (int) $statusCounts->sum() - (int) ($statusCounts['active'] ?? 0),
            'customers_with_active_loans' => $withActiveLoans,
            'customers_with_overdue_loans' => $withOverdueLoans,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(fn (Customer $customer) => [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
            ],
            'phone' => $customer->phone,
            'status' => $customer->status,
            'number_of_loans' => (int) $customer->loans_count,
            'total_borrowed' => $this->formatAmount($customer->total_borrowed ?? 0),
            'total_repaid' => $this->formatAmount($customer->total_repaid ?? 0),
            'outstanding_amount' => $this->formatAmount($customer->total_outstanding ?? 0),
        ])->all();
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
