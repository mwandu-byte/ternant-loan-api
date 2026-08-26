<?php

namespace App\Services\Repayment;

use App\Exceptions\Repayment\LoanNotEligibleForRepaymentScheduleException;
use App\Exceptions\Repayment\RepaymentScheduleAlreadyExistsException;
use App\Exceptions\Repayment\RepaymentScheduleNotFoundException;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentSchedule;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class RepaymentScheduleService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        return $this->applyFilters(RepaymentSchedule::query(), $filters)->paginate(
            min((int) ($filters['per_page'] ?? 15), 100),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
    }

    public function find(int $id): RepaymentSchedule
    {
        $repaymentSchedule = RepaymentSchedule::withSum('penalties', 'amount')->find($id);

        if ($repaymentSchedule === null) {
            throw new RepaymentScheduleNotFoundException;
        }

        return $repaymentSchedule;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForLoan(Loan $loan, array $filters): LengthAwarePaginator
    {
        return $this->applyFilters($loan->repaymentSchedules(), $filters)->paginate(
            min((int) ($filters['per_page'] ?? 15), 100),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
    }

    /**
     * @return Collection<int, RepaymentSchedule>
     */
    public function generateForLoan(Loan $loan): Collection
    {
        if ($loan->status !== 'active') {
            throw new LoanNotEligibleForRepaymentScheduleException;
        }

        if ($loan->repaymentSchedules()->exists()) {
            throw new RepaymentScheduleAlreadyExistsException;
        }

        $frequency = RepaymentFrequency::where('code', $loan->repayment_frequency)->firstOrFail();
        $term = (int) $loan->repayment_term;

        return DB::transaction(function () use ($loan, $frequency, $term) {
            [$principalInstallments, $interestInstallments] = [
                $this->allocateAmount((float) $loan->principal_amount, $term),
                $this->allocateAmount((float) $loan->interest_amount, $term),
            ];

            $startDate = Carbon::parse($loan->start_date);

            for ($i = 1; $i <= $term; $i++) {
                $principal = $principalInstallments[$i - 1];
                $interest = $interestInstallments[$i - 1];
                $total = round($principal + $interest, 2);

                $loan->repaymentSchedules()->create([
                    'installment_number' => $i,
                    'due_date' => $this->calculateInstallmentDueDate($startDate, $i, $frequency),
                    'principal_amount' => $principal,
                    'interest_amount' => $interest,
                    'total_amount' => $total,
                    'outstanding_amount' => $total,
                    'status' => 'pending',
                ]);
            }

            $schedule = $loan->repaymentSchedules()->orderBy('installment_number')->get();

            $this->assertTotalsMatchLoan($loan, $schedule);

            return $schedule;
        });
    }

    /**
     * @param  Builder<RepaymentSchedule>|HasMany<RepaymentSchedule, Loan>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters)
    {
        $query->withSum('penalties', 'amount');

        if (! empty($filters['loan_id'])) {
            $query->where('loan_id', $filters['loan_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['due_date_from'])) {
            $query->whereDate('due_date', '>=', $filters['due_date_from']);
        }

        if (! empty($filters['due_date_to'])) {
            $query->whereDate('due_date', '<=', $filters['due_date_to']);
        }

        return $query->orderBy('due_date')->orderBy('installment_number');
    }

    private function calculateInstallmentDueDate(Carbon $startDate, int $installmentNumber, RepaymentFrequency $frequency): Carbon
    {
        $units = $frequency->interval_value * $installmentNumber;

        return match ($frequency->interval_unit) {
            'day' => $startDate->copy()->addDays($units),
            'week' => $startDate->copy()->addWeeks($units),
            'month' => $startDate->copy()->addMonthsNoOverflow($units),
            'year' => $startDate->copy()->addYears($units),
            default => throw new InvalidArgumentException("Unsupported repayment interval unit: {$frequency->interval_unit}"),
        };
    }

    /**
     * Splits an amount evenly across $count installments, rounded to 2
     * decimal places, with any rounding remainder absorbed entirely into
     * the final installment so the sum always equals the original amount.
     *
     * @return array<int, float>
     */
    private function allocateAmount(float $amount, int $count): array
    {
        $base = round($amount / $count, 2);
        $installments = array_fill(0, $count - 1, $base);
        $installments[] = round($amount - ($base * ($count - 1)), 2);

        return $installments;
    }

    /**
     * @param  Collection<int, RepaymentSchedule>  $schedule
     */
    private function assertTotalsMatchLoan(Loan $loan, Collection $schedule): void
    {
        foreach (['principal_amount', 'interest_amount', 'total_amount'] as $field) {
            $sum = number_format((float) $schedule->sum($field), 2, '.', '');
            $expected = number_format((float) $loan->{$field}, 2, '.', '');

            if ($sum !== $expected) {
                throw new RuntimeException('Repayment schedule generation produced inconsistent totals.');
            }
        }
    }
}
