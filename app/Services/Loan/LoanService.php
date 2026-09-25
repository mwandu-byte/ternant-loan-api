<?php

namespace App\Services\Loan;

use App\Exceptions\Loan\LoanNotEditableException;
use App\Exceptions\Loan\LoanNotFoundException;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Services\ApplicationFee\ApplicationFeeService;
use App\Services\Guarantor\GuarantorService;
use App\Services\LoanConfiguration\InterestRuleService;
use App\Services\LoanConfiguration\LoanAmountConfigurationService;
use App\Services\LoanConfiguration\RepaymentFrequencyService;
use App\Services\Payment\PaymentService;
use App\Services\Repayment\RepaymentScheduleService;
use App\Support\AccessScope;
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
        private readonly RepaymentScheduleService $repaymentScheduleService,
        private readonly PaymentService $paymentService,
        private readonly GuarantorService $guarantorService,
        private readonly ApplicationFeeService $applicationFeeService,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Loan::query()->visibleTo(auth()->user())->with(['customer', 'collaterals', 'repaymentSchedules']);

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
        $loan = Loan::with(['customer', 'collaterals', 'repaymentSchedules', 'guarantors.customer', 'applicationFee'])->find($id);

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
        $customerQuery = Customer::query()->with('business');

        // Direct service calls with no authenticated user (console, jobs)
        // are not tenant-restricted; every HTTP path always has a user.
        if (auth()->user() !== null) {
            AccessScope::restrictToBusiness($customerQuery, auth()->user());
        }

        $customer = $customerQuery->findOrFail($data['customer_id']);
        $principal = (float) $data['principal_amount'];

        // Loan rules always come from the customer's business, whoever
        // is acting (a tenant user, or a platform user lending on its behalf).
        $this->loanAmountConfigurationService->assertWithinRange($principal, $customer->business_id);

        $interestRule = $this->interestRuleService->resolveApplicableRule($principal, $customer->business_id);
        $configuredRate = (float) $interestRule->interest_rate;

        $hasDiscount = (bool) ($data['has_discount'] ?? false);
        $discountRate = $hasDiscount ? (float) $data['discount_rate'] : null;
        $appliedRate = $hasDiscount ? $discountRate : $configuredRate;

        $interestAmount = round($principal * $appliedRate / 100, 2);
        $totalAmount = round($principal + $interestAmount, 2);

        $frequency = $this->repaymentFrequencyService->resolveActiveByCode($data['repayment_frequency'], $customer->business_id);

        $dueDate = $this->calculateDueDate(
            Carbon::parse($data['start_date']),
            (int) $data['repayment_term'],
            $frequency,
        );

        $collateralIds = $data['collateral_ids'] ?? [];
        $this->assertCollateralsBelongToCustomer($customer, $collateralIds);

        $guarantors = $data['guarantors'] ?? [];
        $this->assertBusinessRequirementsMet($customer, $guarantors);

        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use (
                    $customer, $data, $principal, $configuredRate, $hasDiscount, $discountRate,
                    $appliedRate, $interestAmount, $totalAmount, $dueDate, $collateralIds, $guarantors
                ) {
                    $loan = $customer->loans()->create([
                        'business_id' => $customer->business_id,
                        'created_by' => auth()->id(),
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

                    if ($guarantors !== []) {
                        $this->guarantorService->createMany($loan, $guarantors);
                    }

                    if ($customer->business?->requires_application_fee) {
                        $this->applicationFeeService->attachToLoan($customer, $loan);
                    }

                    if ($loan->status === 'active') {
                        $this->repaymentScheduleService->generateForLoan($loan);
                        $this->disburseForActivation($loan, $data);
                    }

                    return $loan->load(['customer', 'collaterals', 'repaymentSchedules', 'guarantors.customer', 'applicationFee']);
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
     * Enforces the borrower's business rules before anything is written:
     * requires_guarantor needs at least one guarantor in the request, and
     * requires_application_fee needs a paid, not-yet-used fee on file.
     *
     * @param  array<int, array<string, mixed>>  $guarantors
     */
    private function assertBusinessRequirementsMet(Customer $customer, array $guarantors): void
    {
        $business = $customer->business;
        $errors = [];

        if ($business?->requires_guarantor && $guarantors === []) {
            $errors['guarantors'] = ['At least one guarantor is required for this business.'];
        }

        if ($business?->requires_application_fee && ! $this->applicationFeeService->hasAvailableFee($customer)) {
            $errors['application_fee'] = ['A paid application fee is required before this loan can be created.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->guarantorService->assertValid($customer, $guarantors);
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

            if ($data['status'] === 'completed' && ! $loan->isFullyPaid()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot mark this loan as completed while it still has an outstanding balance.'],
                ]);
            }
        }

        $oldStatus = $loan->status;

        return DB::transaction(function () use ($loan, $data, $oldStatus) {
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
                    $loan->business_id,
                );

                $updateData['due_date'] = $this->calculateDueDate(
                    Carbon::parse($data['start_date'] ?? $loan->start_date),
                    (int) ($data['repayment_term'] ?? $loan->repayment_term),
                    $frequency,
                );
            }

            $loan->update($updateData);

            // Only the non-active -> active transition generates a
            // schedule and disburses the loan. active -> active
            // (unrelated field changes) and any other transition must
            // never trigger either.
            if ($oldStatus !== 'active' && $loan->status === 'active') {
                $this->repaymentScheduleService->generateForLoan($loan);
                $this->disburseForActivation($loan, $data);
            }

            return $loan->refresh()->load(['customer', 'collaterals', 'repaymentSchedules']);
        });
    }

    public function delete(Loan $loan): string
    {
        // Only a pending loan (never disbursed) can be removed. An active
        // loan can no longer be cancelled at all — once disbursed, it can
        // only move forward to completed — and terminal loans are already
        // immutable.
        if ($loan->status !== 'pending') {
            throw new LoanNotEditableException;
        }

        $loan->delete();

        return 'Loan deleted successfully';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function disburseForActivation(Loan $loan, array $data): void
    {
        $this->paymentService->disburse($loan, [
            'amount' => $loan->principal_amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => $data['payment_method'] ?? config('payment.methods.0', 'cash'),
            'reference_no' => $data['payment_reference_no'] ?? null,
            'notes' => null,
        ]);
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
            'active' => ['completed'],
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
