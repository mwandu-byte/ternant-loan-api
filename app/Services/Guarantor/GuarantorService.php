<?php

namespace App\Services\Guarantor;

use App\Exceptions\Loan\LoanNotEditableException;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class GuarantorService
{
    private const INLINE_FIELDS = ['full_name', 'phone', 'identification_type', 'identification_number', 'address'];

    /**
     * @return Collection<int, Guarantor>
     */
    public function listForLoan(Loan $loan): Collection
    {
        return $loan->guarantors()->with('customer')->orderBy('id')->get();
    }

    public function find(Loan $loan, int $id): Guarantor
    {
        return $loan->guarantors()->with('customer')->findOrFail($id);
    }

    /**
     * Validates a batch of guarantor rows against a borrower without
     * persisting anything, so callers can fail before opening a transaction.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function assertValid(Customer $borrower, array $rows): void
    {
        $seen = [];

        foreach ($rows as $index => $row) {
            $this->assertRow($borrower, $row, "guarantors.{$index}", $seen);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, Guarantor>
     */
    public function createMany(Loan $loan, array $rows): Collection
    {
        $created = new Collection;

        foreach ($rows as $row) {
            $created->push($this->persist($loan, $row));
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(Loan $loan, array $data): Guarantor
    {
        $this->assertLoanEditable($loan);

        $existing = $loan->guarantors()->whereNotNull('guarantor_customer_id')
            ->pluck('guarantor_customer_id')->all();

        $this->assertRow($loan->customer, $data, 'guarantor_customer_id', $existing);

        return $this->persist($loan, $data)->load('customer');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Loan $loan, Guarantor $guarantor, array $data): Guarantor
    {
        $this->assertLoanEditable($loan);

        $merged = array_merge($guarantor->only([...self::INLINE_FIELDS, 'guarantor_customer_id', 'relationship']), $data);

        $others = $loan->guarantors()->whereKeyNot($guarantor->id)
            ->whereNotNull('guarantor_customer_id')->pluck('guarantor_customer_id')->all();

        $this->assertRow($loan->customer, $merged, 'guarantor_customer_id', $others);

        // Switching between "existing customer" and "inline details" must
        // not leave stale identity data from the other mode behind.
        if (! empty($merged['guarantor_customer_id'])) {
            $merged = array_merge($merged, array_fill_keys(self::INLINE_FIELDS, null));
        }

        $guarantor->update($merged);

        return $guarantor->load('customer');
    }

    public function delete(Loan $loan, Guarantor $guarantor): void
    {
        $this->assertLoanEditable($loan);

        // The business rule is enforced when a loan is created, so a loan
        // must never be left below the minimum afterwards either.
        if ($loan->business?->requires_guarantor && $loan->guarantors()->count() <= 1) {
            throw ValidationException::withMessages([
                'guarantor' => ['This business requires at least one guarantor on every loan.'],
            ]);
        }

        $guarantor->delete();
    }

    private function assertLoanEditable(Loan $loan): void
    {
        if ($loan->status !== 'pending') {
            throw new LoanNotEditableException('Guarantors can only be changed while the loan is pending.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $seenCustomerIds
     */
    private function assertRow(Customer $borrower, array $row, string $field, array &$seenCustomerIds): void
    {
        $customerId = $row['guarantor_customer_id'] ?? null;

        if ($customerId === null) {
            $missing = [];

            foreach (['full_name', 'phone', 'identification_type', 'identification_number'] as $required) {
                if (empty($row[$required])) {
                    $missing[$field.'.'.$required] = ["The {$required} field is required when no guarantor customer is given."];
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            return;
        }

        $customerId = (int) $customerId;

        if ($customerId === $borrower->id) {
            throw ValidationException::withMessages([$field => ['A borrower cannot be their own guarantor.']]);
        }

        // Guarantors are matched inside the borrower's business only, so
        // an ID from another tenant is indistinguishable from a missing one.
        $exists = Customer::query()
            ->whereKey($customerId)
            ->where('business_id', $borrower->business_id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([$field => ['The selected guarantor customer is invalid.']]);
        }

        if (in_array($customerId, $seenCustomerIds, true)) {
            throw ValidationException::withMessages([$field => ['The same customer cannot be added as a guarantor twice.']]);
        }

        $seenCustomerIds[] = $customerId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persist(Loan $loan, array $row): Guarantor
    {
        $customerId = $row['guarantor_customer_id'] ?? null;

        return $loan->guarantors()->create([
            'business_id' => $loan->business_id,
            'guarantor_customer_id' => $customerId,
            // Identity of an existing customer lives on that customer —
            // never copied here.
            ...($customerId ? array_fill_keys(self::INLINE_FIELDS, null) : collect($row)->only(self::INLINE_FIELDS)->all()),
            'relationship' => $row['relationship'],
            'created_by' => auth()->id(),
        ]);
    }
}
