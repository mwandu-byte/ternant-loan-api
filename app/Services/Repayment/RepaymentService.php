<?php

namespace App\Services\Repayment;

use App\Exceptions\Loan\LoanNotFoundException;
use App\Exceptions\Receipt\DuplicateReceiptReferenceException;
use App\Exceptions\Repayment\RepaymentExceedsOutstandingAmountException;
use App\Exceptions\Repayment\RepaymentNotFoundException;
use App\Exceptions\Repayment\RepaymentScheduleDoesNotBelongToLoanException;
use App\Exceptions\Repayment\RepaymentScheduleNotFoundException;
use App\Models\Loan;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RepaymentService
{
    private const MAX_RECEIPT_NO_RETRIES = 3;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Repayment::query()->with(['receipt', 'loan', 'repaymentSchedule']);

        if (! empty($filters['loan_id'])) {
            $query->where('loan_id', $filters['loan_id']);
        }

        if (! empty($filters['repayment_schedule_id'])) {
            $query->where('repayment_schedule_id', $filters['repayment_schedule_id']);
        }

        if (! empty($filters['repayment_date_from'])) {
            $query->whereDate('repayment_date', '>=', $filters['repayment_date_from']);
        }

        if (! empty($filters['repayment_date_to'])) {
            $query->whereDate('repayment_date', '<=', $filters['repayment_date_to']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('receipt', function ($q) use ($search) {
                $q->where('receipt_no', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(int $id): Repayment
    {
        $repayment = Repayment::with(['receipt', 'loan', 'repaymentSchedule'])->find($id);

        if ($repayment === null) {
            throw new RepaymentNotFoundException;
        }

        return $repayment;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Repayment
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($data) {
                    $loan = Loan::find($data['loan_id']);

                    if ($loan === null) {
                        throw new LoanNotFoundException;
                    }

                    // Locked inside the transaction, before any balance math,
                    // so two concurrent repayments against the same schedule
                    // are serialized rather than both reading a stale
                    // outstanding amount.
                    $schedule = RepaymentSchedule::where('id', $data['repayment_schedule_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($schedule === null) {
                        throw new RepaymentScheduleNotFoundException;
                    }

                    if ((int) $schedule->loan_id !== (int) $loan->id) {
                        throw new RepaymentScheduleDoesNotBelongToLoanException;
                    }

                    // Recomputed after the lock — never trust a pre-lock read.
                    $totalRepaid = bcadd((string) Repayment::where('repayment_schedule_id', $schedule->id)->sum('amount'), '0', 2);
                    $outstanding = bcsub((string) $schedule->total_amount, $totalRepaid, 2);

                    $amount = bcadd((string) $data['amount'], '0', 2);

                    if (bccomp($amount, $outstanding, 2) === 1) {
                        throw new RepaymentExceedsOutstandingAmountException;
                    }

                    $receipt = $this->createReceipt($data, $amount);

                    $repayment = Repayment::create([
                        'loan_id' => $loan->id,
                        'repayment_schedule_id' => $schedule->id,
                        'receipt_id' => $receipt->id,
                        'amount' => $amount,
                        'repayment_date' => $data['repayment_date'],
                        'notes' => $data['notes'] ?? null,
                        'received_by' => auth()->id(),
                    ]);

                    $newTotalRepaid = bcadd($totalRepaid, $amount, 2);
                    $newOutstanding = bcsub((string) $schedule->total_amount, $newTotalRepaid, 2);

                    if (bccomp($newOutstanding, '0.00', 2) === -1) {
                        $newOutstanding = '0.00';
                    }

                    $schedule->update([
                        'outstanding_amount' => $newOutstanding,
                        'status' => bccomp($newOutstanding, '0.00', 2) === 0 ? 'paid' : 'partially_paid',
                    ]);

                    return $repayment->load(['receipt', 'loan', 'repaymentSchedule']);
                });
            } catch (QueryException $e) {
                if ($this->isDuplicateReferenceNoError($e)) {
                    throw new DuplicateReceiptReferenceException;
                }

                $attempt++;

                if ($attempt >= self::MAX_RECEIPT_NO_RETRIES || ! $this->isDuplicateReceiptNoError($e)) {
                    throw $e;
                }

                // Our own receipt_no generation race — regenerate and retry.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createReceipt(array $data, string $amount): Receipt
    {
        return Receipt::create([
            'receipt_no' => $this->generateReceiptNo(),
            'amount' => $amount,
            'receipt_date' => $data['repayment_date'],
            'payment_method' => $data['payment_method'],
            'reference_no' => $data['reference_no'] ?? null,
            'received_by' => auth()->id(),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function generateReceiptNo(): string
    {
        $year = now()->year;
        $prefix = config('payment.receipt_prefix', 'RC');

        $last = Receipt::where('receipt_no', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $nextSequence = $last === null ? 1 : ((int) substr($last->receipt_no, -6)) + 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $nextSequence);
    }

    private function isDuplicateReceiptNoError(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'receipt_no');
    }

    private function isDuplicateReferenceNoError(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'reference_no');
    }
}
