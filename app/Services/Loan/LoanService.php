<?php

namespace App\Services\Loan;

use App\Exceptions\Loan\LoanNotEditableException;
use App\Exceptions\Loan\LoanNotFoundException;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Services\LoanConfiguration\InterestRuleService;
use App\Services\LoanConfiguration\LoanAmountConfigurationService;
use App\Services\LoanConfiguration\RepaymentFrequencyService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LoanService
{
    private const MAX_REFERENCE_RETRIES = 3;

    private const TERMINAL_STATUSES = ['completed', 'cancelled'];

    public function __construct(
        private readonly LoanAmountConfigurationService $loanAmountConfigurationService,
        private readonly InterestRuleService $interestRuleService,
        private readonly RepaymentFrequencyService $repaymentFrequencyService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Loan::query()->with(['customer', 'collaterals']);

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $query->where('reference_no', 'like', '%'.$filters['search'].'%');
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(int $id): Loan
    {
        $loan = Loan::with(['customer', 'collaterals'])->find($id);

        if ($loan === null) {
            throw new LoanNotFoundException;
        }

        return $loan;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Loan
    {
        $customer = Customer::findOrFail($data['customer_id']);
        $principal = (float) $data['principal_amount'];

        $this->loanAmountConfigurationService->assertWithinRange($principal);

        $interestRule = $this->interestRuleService->resolveApplicableRule($principal);
        $configuredRate = (float) $interestRule->interest_rate;

        $hasDiscount = (bool) ($data['has_discount'] ?? false);
        $discountRate = $hasDiscount ? (float) $data['discount_rate'] : null;
        $appliedRate = $hasDiscount ? $discountRate : $configuredRate;

        $interestAmount = round($principal * $appliedRate / 100, 2);
        $totalAmount = round($principal + $interestAmount, 2);

        $frequency = $this->repaymentFrequencyService->resolveActiveByCode($data['repayment_frequency']);

        $dueDate = $this->calculateDueDate(
            Carbon::parse($data['start_date']),
            (int) $data['repayment_term'],
            $frequency,
        );

        $collateralIds = $data['collateral_ids'] ?? [];
        $this->assertCollateralsBelongToCustomer($customer, $collateralIds);

        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use (
                    $customer, $data, $principal, $configuredRate, $hasDiscount, $discountRate,
                    $appliedRate, $interestAmount, $totalAmount, $dueDate, $collateralIds
                ) {
                    $loan = $customer->loans()->create([
                        'reference_no' => $this->generateReferenceNo(),
                        'principal_amount' => $principal,
                        'interest_rate' => $configuredRate,
                        'interest_amount' => $interestAmount,
                        'total_amount' => $totalAmount,
                        'has_discount' => $hasDiscount,
                        'discount_rate' => $discountRate,
                        'applied_interest_rate' => $appliedRate,
                        'repayment_frequency' => $data['repayment_frequency'],
                        'repayment_term' => $data['repayment_term'],
                        'start_date' => $data['start_date'],
                        'due_date' => $dueDate,
                        'status' => $data['status'] ?? 'pending',
                        'notes' => $data['notes'] ?? null,
                    ]);

                    if (! empty($collateralIds)) {
                        $loan->collaterals()->sync($collateralIds);
                    }

                    return $loan->load(['customer', 'collaterals']);
                });
            } catch (QueryException $e) {
                $attempt++;

                if ($attempt >= self::MAX_REFERENCE_RETRIES || ! $this->isDuplicateReferenceError($e)) {
                    throw $e;
                }

                // Two concurrent requests can both observe "no loan for
                // this year yet" (nothing exists to lockForUpdate()) and
                // both compute sequence 000001 — this is the one race
                // window lockForUpdate() cannot close on its own. The
                // unique index on reference_no turns that race into a
                // clean, retryable failure for the loser instead of a
                // silent duplicate.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Loan $loan, array $data): Loan
    {
        if (in_array($loan->status, self::TERMINAL_STATUSES, true)) {
            throw new LoanNotEditableException;
        }

        if ($loan->status === 'active') {
            $lockedFields = ['repayment_frequency', 'repayment_term', 'start_date', 'collateral_ids'];
            $errors = [];

            foreach ($lockedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $errors[$field] = ['This field cannot be changed once the loan is active.'];
                }
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
        }

        if (array_key_exists('status', $data)) {
            $this->assertValidStatusTransition($loan->status, $data['status']);
        }

        return DB::transaction(function () use ($loan, $data) {
            if (array_key_exists('collateral_ids', $data)) {
                $this->assertCollateralsBelongToCustomer($loan->customer, $data['collateral_ids']);
                $loan->collaterals()->sync($data['collateral_ids']);
            }

            $updateData = collect($data)
                ->only(['repayment_frequency', 'repayment_term', 'start_date', 'status', 'notes'])
                ->toArray();

            $recalculateDueDate = array_key_exists('start_date', $data)
                || array_key_exists('repayment_term', $data)
                || array_key_exists('repayment_frequency', $data);

            if ($recalculateDueDate) {
                $frequency = $this->repaymentFrequencyService->resolveActiveByCode(
                    $data['repayment_frequency'] ?? $loan->repayment_frequency,
                );

                $updateData['due_date'] = $this->calculateDueDate(
                    Carbon::parse($data['start_date'] ?? $loan->start_date),
                    (int) ($data['repayment_term'] ?? $loan->repayment_term),
                    $frequency,
                );
            }

            $loan->update($updateData);

            return $loan->refresh()->load(['customer', 'collaterals']);
        });
    }

    public function delete(Loan $loan): string
    {
        if (in_array($loan->status, self::TERMINAL_STATUSES, true)) {
            throw new LoanNotEditableException;
        }

        if ($loan->status === 'active') {
            $loan->update(['status' => 'cancelled']);

            return 'Loan cancelled successfully';
        }

        $loan->delete();

        return 'Loan deleted successfully';
    }

    private function calculateDueDate(Carbon $startDate, int $term, RepaymentFrequency $frequency): Carbon
    {
        $totalUnits = $frequency->interval_value * $term;

        return match ($frequency->interval_unit) {
            'day' => $startDate->copy()->addDays($totalUnits),
            'week' => $startDate->copy()->addWeeks($totalUnits),
            'month' => $startDate->copy()->addMonths($totalUnits),
            'year' => $startDate->copy()->addYears($totalUnits),
            default => throw new InvalidArgumentException("Unsupported repayment interval unit: {$frequency->interval_unit}"),
        };
    }

    /**
     * @param  array<int, int>  $collateralIds
     */
    private function assertCollateralsBelongToCustomer(Customer $customer, array $collateralIds): void
    {
        if (empty($collateralIds)) {
            return;
        }

        $ownedCount = $customer->collaterals()->whereIn('id', $collateralIds)->count();

        if ($ownedCount !== count(array_unique($collateralIds))) {
            throw ValidationException::withMessages([
                'collateral_ids' => ['One or more selected collateral records do not belong to this customer.'],
            ]);
        }
    }

    private function assertValidStatusTransition(string $current, string $requested): void
    {
        if ($current === $requested) {
            return;
        }

        $allowed = [
            'pending' => ['active', 'cancelled'],
            'active' => ['completed', 'cancelled'],
        ];

        if (! in_array($requested, $allowed[$current] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition loan status from '{$current}' to '{$requested}'."],
            ]);
        }
    }

    private function generateReferenceNo(): string
    {
        $year = now()->year;
        $prefix = config('loan.reference_prefix', 'LN');

        $last = Loan::where('reference_no', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $nextSequence = $last === null ? 1 : ((int) substr($last->reference_no, -6)) + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $nextSequence);
    }

    private function isDuplicateReferenceError(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation, raised for
        // unique-key conflicts by both MySQL (production) and SQLite
        // (tests). Restricting to messages mentioning reference_no keeps
        // this from swallowing an unrelated integrity error inside the
        // same transaction.
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'reference_no');
    }
}
