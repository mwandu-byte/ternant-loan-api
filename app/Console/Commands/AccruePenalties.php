<?php

namespace App\Console\Commands;

use App\Services\Penalty\PenaltyAccrualService;
use Illuminate\Console\Command;

class AccruePenalties extends Command
{
    /**
     * @var string
     */
    protected $signature = 'penalties:accrue';

    /**
     * @var string
     */
    protected $description = 'Charge penalties to repayment schedules past their grace period, per configured penalty rules.';

    public function __construct(
        private readonly PenaltyAccrualService $penaltyAccrualService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->penaltyAccrualService->accrue();

        $this->info(sprintf(
            'Penalty accrual complete: %d applied, %d skipped, %d failed.',
            $result['applied'],
            $result['skipped'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
