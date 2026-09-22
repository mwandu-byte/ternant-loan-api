<?php

namespace App\Services\ApplicationFee;

use App\Models\ApplicationFee;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ApplicationFeeService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForCustomer(Customer $customer, array $filters = []): LengthAwarePaginator
    {
        $query = $customer->applicationFees()->with('customer')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(int $id): ApplicationFee
    {
        return ApplicationFee::with('customer')->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data): ApplicationFee
    {
        $status = $data['status'] ?? 'paid';
        $reference = $data['reference_no'] ?? null;

        if ($reference !== null && ApplicationFee::where('business_id', $customer->business_id)
            ->where('reference_no', $reference)->exists()) {
            throw ValidationException::withMessages(['reference_no' => ['This reference number has already been used.']]);
        }

        return ApplicationFee::create([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'amount' => $data['amount'],
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($data['paid_at'] ?? now()->toDateString()) : null,
            'payment_method' => $data['payment_method'] ?? null,
            'reference_no' => $reference,
            'receipt_no' => $data['receipt_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ])->load('customer');
    }

    /**
     * Whether the customer has a paid fee that no loan has consumed yet.
     */
    public function hasAvailableFee(Customer $customer): bool
    {
        return $customer->applicationFees()->available()->exists();
    }

    /**
     * Consumes the oldest available paid fee for the loan. Must run inside
     * the loan-creation transaction; the row lock stops two concurrent
     * loans from claiming the same fee.
     */
    public function attachToLoan(Customer $customer, Loan $loan): void
    {
        $fee = $customer->applicationFees()->available()->orderBy('id')->lockForUpdate()->first();

        if ($fee === null) {
            throw ValidationException::withMessages([
                'application_fee' => ['A paid application fee is required before this loan can be created.'],
            ]);
        }

        $fee->update(['loan_id' => $loan->id]);
    }
}
