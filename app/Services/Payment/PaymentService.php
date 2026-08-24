<?php

namespace App\Services\Payment;

use App\Exceptions\Payment\InvalidDisbursementAmountException;
use App\Exceptions\Payment\LoanAlreadyDisbursedException;
use App\Exceptions\Payment\LoanNotEligibleForDisbursementException;
use App\Exceptions\Payment\PaymentNotFoundException;
use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Payment::query()->with('loan');

        if (! empty($filters['loan_id'])) {
            $query->where('loan_id', $filters['loan_id']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['payment_date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['payment_date_from']);
        }

        if (! empty($filters['payment_date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['payment_date_to']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(int $id): Payment
    {
        $payment = Payment::with('loan')->find($id);

        if ($payment === null) {
            throw new PaymentNotFoundException;
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function disburse(Loan $loan, array $data): Payment
    {
        if ($loan->status !== 'active') {
            throw new LoanNotEligibleForDisbursementException;
        }

        return DB::transaction(function () use ($loan, $data) {
            $lockedLoan = Loan::where('id', $loan->id)->lockForUpdate()->first();

            if ($lockedLoan->payments()->exists()) {
                throw new LoanAlreadyDisbursedException;
            }

            $amount = bcadd((string) $data['amount'], '0', 2);

            if (bccomp($amount, (string) $lockedLoan->principal_amount, 2) !== 0) {
                throw new InvalidDisbursementAmountException;
            }

            try {
                return $lockedLoan->payments()->create([
                    'amount' => $amount,
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $data['payment_method'],
                    'reference_no' => $data['reference_no'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'paid_by' => auth()->id(),
                ])->load('loan');
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'loan_id')) {
                    throw new LoanAlreadyDisbursedException;
                }

                throw $e;
            }
        });
    }
}
