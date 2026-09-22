<?php

namespace App\Services\Reports;

use App\Support\AccessScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Money IN (loan repayments, via `repayments`/`receipts`) vs money OUT
 * (loan disbursements, via `payments`) — built entirely from existing
 * tables, never a new cash-flow table.
 *
 * Transaction-level records from both tables are combined via a database
 * UNION ALL and paginated at the DB level (true LIMIT/OFFSET), so request
 * cost is bounded by per_page regardless of table size or page depth —
 * never by materializing both tables and merging/sorting in PHP.
 */
class CashFlowReportService
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
    private function outQuery(array $filters): ?Builder
    {
        if (! empty($filters['type']) && $filters['type'] !== 'disbursement') {
            return null;
        }

        $query = DB::table('payments')
            ->join('loans', 'loans.id', '=', 'payments.loan_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->selectRaw("'disbursement' as type, payments.id as source_id, payments.payment_date as txn_date, payments.amount as amount, payments.reference_no as reference, payments.payment_method as method, payments.notes as notes, payments.loan_id as loan_id, loans.reference_no as loan_reference, loans.customer_id as customer_id, customers.full_name as customer_name, payments.paid_by as user_id");

        if (! AccessScope::isPlatformUser(auth()->user())) {
            $query->where('loans.business_id', auth()->user()->business_id);
        }

        if (! AccessScope::isUnrestricted(auth()->user())) {
            $query->where('loans.created_by', auth()->id());
        }

        if (! empty($filters['customer_id'])) {
            $query->where('loans.customer_id', $filters['customer_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('payments.paid_by', $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('payments.payment_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('payments.payment_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function inQuery(array $filters): ?Builder
    {
        if (! empty($filters['type']) && $filters['type'] !== 'collection') {
            return null;
        }

        $query = DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->join('customers', 'customers.id', '=', 'loans.customer_id')
            ->join('receipts', 'receipts.id', '=', 'repayments.receipt_id')
            ->selectRaw("'collection' as type, repayments.id as source_id, repayments.repayment_date as txn_date, repayments.amount as amount, receipts.receipt_no as reference, receipts.payment_method as method, repayments.notes as notes, repayments.loan_id as loan_id, loans.reference_no as loan_reference, loans.customer_id as customer_id, customers.full_name as customer_name, repayments.received_by as user_id");

        if (! AccessScope::isPlatformUser(auth()->user())) {
            $query->where('loans.business_id', auth()->user()->business_id);
        }

        if (! AccessScope::isUnrestricted(auth()->user())) {
            $query->where('loans.created_by', auth()->id());
        }

        if (! empty($filters['customer_id'])) {
            $query->where('loans.customer_id', $filters['customer_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('repayments.received_by', $filters['user_id']);
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
        $out = $this->outQuery($filters);
        $in = $this->inQuery($filters);

        // Validation on the controller restricts `type` to
        // disbursement|collection|null, so at least one side is always
        // non-null here.
        $union = match (true) {
            $out !== null && $in !== null => $out->unionAll($in),
            $out !== null => $out,
            default => $in,
        };

        $union->orderBy('txn_date', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $union->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summarize(array $filters): array
    {
        $moneyOut = '0.00';
        $moneyIn = '0.00';

        if (empty($filters['type']) || $filters['type'] === 'disbursement') {
            $out = DB::table('payments')
                ->join('loans', 'loans.id', '=', 'payments.loan_id')
                ->when(
                    ! AccessScope::isUnrestricted(auth()->user()),
                    fn ($q) => $q->where('loans.created_by', auth()->id()),
                )
                ->when(! empty($filters['customer_id']), fn ($q) => $q->where('loans.customer_id', $filters['customer_id']))
                ->when(! empty($filters['user_id']), fn ($q) => $q->where('payments.paid_by', $filters['user_id']))
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('payments.payment_date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('payments.payment_date', '<=', $filters['date_to']))
                ->sum('payments.amount');
            $moneyOut = $this->formatAmount($out);
        }

        if (empty($filters['type']) || $filters['type'] === 'collection') {
            $in = DB::table('repayments')
                ->join('loans', 'loans.id', '=', 'repayments.loan_id')
                ->when(
                    ! AccessScope::isUnrestricted(auth()->user()),
                    fn ($q) => $q->where('loans.created_by', auth()->id()),
                )
                ->when(! empty($filters['customer_id']), fn ($q) => $q->where('loans.customer_id', $filters['customer_id']))
                ->when(! empty($filters['user_id']), fn ($q) => $q->where('repayments.received_by', $filters['user_id']))
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('repayments.repayment_date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('repayments.repayment_date', '<=', $filters['date_to']))
                ->sum('repayments.amount');
            $moneyIn = $this->formatAmount($in);
        }

        return [
            'total_money_in' => $moneyIn,
            'total_money_out' => $moneyOut,
            'net_cash_flow' => $this->formatAmount((float) $moneyIn - (float) $moneyOut),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transform(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->map(fn ($row) => [
            'type' => $row->type === 'disbursement' ? 'money_out' : 'money_in',
            'reference' => $row->reference,
            'amount' => $this->formatAmount($row->amount),
            'date' => $row->txn_date,
            'customer' => $row->customer_id === null ? null : [
                'id' => $row->customer_id,
                'full_name' => $row->customer_name,
            ],
            'loan_reference' => $row->loan_reference,
            'payment_method' => $row->method,
            'description' => $row->notes,
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
