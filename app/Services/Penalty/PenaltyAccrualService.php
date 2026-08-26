<?php

namespace App\Services\Penalty;

use App\Models\RepaymentSchedule;
use App\Services\Repayment\OverdueService;
use Throwable;

/**
 * Batch orchestration only — selecting the overdue population and
 * chunking through it. The per-schedule calculation/idempotency logic
 * lives entirely in PenaltyService so it stays independently testable
 * against a single crafted schedule, without needing to seed an entire
 * overdue population just to exercise the calculation.
 */
class PenaltyAccrualService
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly PenaltyService $penaltyService,
        private readonly OverdueService $overdueService,
    ) {
        //
    }

    /**
     * @return array{applied: int, skipped: int, failed: int}
     */
    public function accrue(): array
    {
        $applied = 0;
        $skipped = 0;
        $failed = 0;

        $this->overdueService
            ->applyOverdueScope(RepaymentSchedule::query()->with('loan'))
            ->chunkById(self::CHUNK_SIZE, function ($schedules) use (&$applied, &$skipped, &$failed) {
                foreach ($schedules as $schedule) {
                    try {
                        $this->penaltyService->apply($schedule) !== null ? $applied++ : $skipped++;
                    } catch (Throwable $e) {
                        // One bad schedule (e.g. an unexpected data issue)
                        // must never abort the rest of the run.
                        $failed++;
                        report($e);
                    }
                }
            });

        return compact('applied', 'skipped', 'failed');
    }
}
