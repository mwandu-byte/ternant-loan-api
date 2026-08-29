<?php

namespace App\Console\Commands;

use App\Models\Loan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairCompletedLoanStatus extends Command
{
    /**
     * @var string
     */
    protected $signature = 'loans:repair-completed-status {--dry-run : List affected loans without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Revert loans incorrectly marked completed while a repayment schedule still has an outstanding balance (data fix for the missing status-transition guard).';

    public function handle(): int
    {
        $loans = Loan::query()
            ->where('status', 'completed')
            ->with('repaymentSchedules')
            ->get()
            ->filter(fn (Loan $loan) => ! $loan->isFullyPaid());

        if ($loans->isEmpty()) {
            $this->info('No affected loans found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Reference No.', 'Outstanding'],
            $loans->map(fn (Loan $loan) => [
                $loan->id,
                $loan->reference_no,
                $loan->repaymentSchedules->sum('outstanding_amount'),
            ]),
        );

        if ($this->option('dry-run')) {
            $this->comment(sprintf('%d loan(s) would be reverted to active. Re-run without --dry-run to apply.', $loans->count()));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($loans) {
            foreach ($loans as $loan) {
                $loan->update(['status' => 'active']);
            }
        });

        $this->info(sprintf('%d loan(s) reverted to active.', $loans->count()));

        return self::SUCCESS;
    }
}
