<?php

namespace App\Services\Reports;

use App\Models\Repayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money IN through actual customer repayments. Sums Repayment.amount, never
 * Receipt.amount — a single receipt can fan out across several repayment
 * schedule installments, so summing receipts would double count. `status`
 * reflects the related repayment schedule's *current* status (a
 * point-in-time read, not a historical snapshot at the moment of payment).
 */
class CollectionReportService
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
        $query = Repayment::query();

        if (! empty($filters['customer_id'])) {
            $query->whereHas('loan', fn (Builder $q) => $q->where('customer_id', $filters['customer_id']));
        }

        // Table-qualified: summarize()'s payment-method breakdown joins
        // `receipts`, which also has a received_by column — an
        // unqualified reference becomes ambiguous once that join is
        // added on top of this same base query.
        if (! empty($filters['user_id'])) {
            $query->where('repayments.received_by', $filters['user_id']);
        }

        if (! empty($filters['status'])) {
            $query->whereHas('repaymentSchedule', fn (Builder $q) => $q->where('status', $filters['status']));
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('repayments.repayment_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('repayments.repayment_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->baseQuery($filters)
            ->with(['loan.customer', 'repaymentSchedule', 'receipt', 'receivedBy'])
            ->orderBy('repayment_date', 'desc');

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
            ->selectRaw('COUNT(*) as repayments_count, COALESCE(SUM(amount), 0) as total_collected')
            ->first();

        $totalReceipts = $this->baseQuery($filters)->distinct('receipt_id')->count('receipt_id');

        $byMethod = $this->baseQuery($filters)
            ->join('receipts', 'receipts.id', '=', 'repayments.receipt_id')
            ->select('receipts.payment_method')
            ->selectRaw('COALESCE(SUM(repayments.amount), 0) as total')
            ->groupBy('receipts.payment_method')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->payment_method => $this->formatAmount($row->total)])
            ->all();

        return [
            'total_collected' => $this->formatAmount($totals->total_collected ?? 0),
            'number_of_repayments' => (int) ($totals->repayments_count ?? 0),
            'total_receipts' => $totalReceipts,
            'collection_by_payment_method' => $byMethod,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(fn (Repayment $repayment) => [
            'receipt_number' => $repayment->receipt?->receipt_no,
            'repayment_reference' => (string) $repayment->id,
            'customer' => $repayment->loan?->customer === null ? null : [
                'id' => $repayment->loan->customer->id,
                'full_name' => $repayment->loan->customer->full_name,
                'phone' => $repayment->loan->customer->phone,
            ],
            'loan_reference' => $repayment->loan?->reference_no,
            'installment_number' => $repayment->repaymentSchedule?->installment_number,
            'amount' => (string) $repayment->amount,
            'payment_method' => $repayment->receipt?->payment_method,
            'repayment_date' => optional($repayment->repayment_date)->toDateString(),
            'receipt_date' => optional($repayment->receipt?->receipt_date)->toDateString(),
            'status' => $repayment->repaymentSchedule?->status,
            'received_by' => $repayment->receivedBy?->name,
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
