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
use Illuminate\Database\Eloquent\Collection;
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
     * @return Collection<int, Repayment>
     */
    public function create(array $data): Collection
    {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($data) {
                    // Locked before any schedule locks, so all concurrent
                    // repayment writes against this loan fully serialize on
                    // this one row.
                    $loan = Loan::where('id', $data['loan_id'])->lockForUpdate()->first();

                    if ($loan === null) {
                        throw new LoanNotFoundException;
                    }

                    // Locked inside the transaction, before any balance math,
                    // so two concurrent repayments against the same schedule
                    // are serialized rather than both reading a stale
                    // outstanding amount.
                    $targetSchedule = RepaymentSchedule::where('id', $data['repayment_schedule_id'])
                        ->lockForUpdate()
                        ->first();

                    if ($targetSchedule === null) {
                        throw new RepaymentScheduleNotFoundException;
                    }

                    if ((int) $targetSchedule->loan_id !== (int) $loan->id) {
                        throw new RepaymentScheduleDoesNotBelongToLoanException;
                    }

                    // Every later installment the excess could roll forward
                    // into — locked up front alongside the target so the
                    // whole allocation is computed against a consistent,
                    // race-free snapshot.
                    $forwardSchedules = RepaymentSchedule::where('loan_id', $loan->id)
                        ->where('installment_number', '>', $targetSchedule->installment_number)
                        ->orderBy('installment_number')
                        ->lockForUpdate()
                        ->get();

                    $candidates = (new Collection([$targetSchedule]))->concat($forwardSchedules);

                    // Recomputed after the locks — never trust a pre-lock read.
                    $repaidTotals = Repayment::whereIn('repayment_schedule_id', $candidates->pluck('id'))
                        ->selectRaw('repayment_schedule_id, SUM(amount) as total')
                        ->groupBy('repayment_schedule_id')
                        ->pluck('total', 'repayment_schedule_id');

                    $outstandingBySchedule = [];

                    foreach ($candidates as $candidate) {
                        $repaid = bcadd((string) ($repaidTotals[$candidate->id] ?? '0'), '0', 2);
                        $outstanding = bcsub((string) $candidate->total_amount, $repaid, 2);

                        if (bccomp($outstanding, '0.00', 2) === -1) {
                            $outstanding = '0.00';
                        }

                        $outstandingBySchedule[$candidate->id] = $outstanding;
                    }

                    $amount = bcadd((string) $data['amount'], '0', 2);

                    // A payment must target an installment that actually has
                    // something owed on it — it never auto-skips forward
                    // from an already-settled target to a later one.
                    if (bccomp($outstandingBySchedule[$targetSchedule->id], '0.00', 2) === 0) {
                        throw new RepaymentExceedsOutstandingAmountException;
                    }

                    $totalCapacity = array_reduce(
                        $outstandingBySchedule,
                        fn (string $carry, string $outstanding) => bcadd($carry, $outstanding, 2),
                        '0.00',
                    );

                    if (bccomp($amount, $totalCapacity, 2) === 1) {
                        throw new RepaymentExceedsOutstandingAmountException;
                    }

                    // One receipt for the whole payment, however many
                    // installments it ends up covering.
                    $receipt = $this->createReceipt($data, $amount);

                    $remaining = $amount;
                    $created = new Collection;

                    foreach ($candidates as $schedule) {
                        if (bccomp($remaining, '0.00', 2) === 0) {
                            break;
                        }

                        $scheduleOutstanding = $outstandingBySchedule[$schedule->id];

                        if (bccomp($scheduleOutstanding, '0.00', 2) === 0) {
                            continue;
                        }

                        $portion = bccomp($remaining, $scheduleOutstanding, 2) === 1
                            ? $scheduleOutstanding
                            : $remaining;

                        $created->push(Repayment::create([
                            'loan_id' => $loan->id,
                            'repayment_schedule_id' => $schedule->id,
                            'receipt_id' => $receipt->id,
                            'amount' => $portion,
                            'repayment_date' => $data['repayment_date'],
                            'notes' => $data['notes'] ?? null,
                            'received_by' => auth()->id(),
                        ]));

                        $newOutstanding = bcsub($scheduleOutstanding, $portion, 2);

                        $schedule->update([
                            'outstanding_amount' => $newOutstanding,
                            'status' => bccomp($newOutstanding, '0.00', 2) === 0 ? 'paid' : 'partially_paid',
                        ]);

                        $remaining = bcsub($remaining, $portion, 2);
                    }

                    // Only ever active -> completed, and only once every
                    // installment on the loan is fully paid.
                    if ($loan->status === 'active' && $loan->isFullyPaid()) {
                        $loan->update(['status' => 'completed']);
                    }

                    return $created->load(['receipt', 'loan', 'repaymentSchedule']);
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
