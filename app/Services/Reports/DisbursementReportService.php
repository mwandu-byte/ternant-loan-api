<?php

namespace App\Services\Reports;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money OUT through loan disbursements. Built entirely on the `payments`
 * table — a Payment is always a disbursement, never a repayment. Payment
 * has no status column, so no status field/filter is offered here (never
 * fabricated).
 */
class DisbursementReportService
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
        $query = Payment::query();

        if (! empty($filters['customer_id'])) {
            $query->whereHas('loan', fn (Builder $q) => $q->where('customer_id', $filters['customer_id']));
        }

        if (! empty($filters['user_id'])) {
            $query->where('paid_by', $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with(['loan.customer', 'paidBy'])
            ->orderBy('payment_date', 'desc');

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
            ->selectRaw('COUNT(*) as disbursements_count, COALESCE(SUM(amount), 0) as total_disbursed, COALESCE(AVG(amount), 0) as average_disbursement')
            ->first();

        return [
            'total_disbursed' => $this->formatAmount($totals->total_disbursed ?? 0),
            'number_of_disbursements' => (int) ($totals->disbursements_count ?? 0),
            'average_disbursement' => $this->formatAmount($totals->average_disbursement ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(fn (Payment $payment) => [
            'payment_reference' => $payment->reference_no,
            'loan_reference' => $payment->loan?->reference_no,
            'customer' => $payment->loan?->customer === null ? null : [
                'id' => $payment->loan->customer->id,
                'full_name' => $payment->loan->customer->full_name,
                'phone' => $payment->loan->customer->phone,
            ],
            'amount' => (string) $payment->amount,
            'payment_method' => $payment->payment_method,
            'payment_date' => optional($payment->payment_date)->toDateString(),
            'notes' => $payment->notes,
            'disbursed_by' => $payment->paidBy?->name,
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
