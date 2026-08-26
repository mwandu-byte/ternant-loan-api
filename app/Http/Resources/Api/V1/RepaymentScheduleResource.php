<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Repayment\OverdueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $loan_id
 * @property int $installment_number
 * @property Carbon $due_date
 * @property string $principal_amount
 * @property string $interest_amount
 * @property string $total_amount
 * @property string $outstanding_amount
 * @property string $status
 * @property string|null $penalties_sum_amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RepaymentScheduleResource extends JsonResource
{
    /**
     * Statuses owned and set by future modules (e.g. Payment Management)
     * are authoritative and must never be overridden by date-based
     * derivation.
     */
    private const AUTHORITATIVE_STATUSES = ['paid', 'partially_paid'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overdueService = app(OverdueService::class);

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'installment_number' => $this->installment_number,
            'due_date' => $this->due_date?->toDateString(),
            'principal_amount' => $this->principal_amount,
            'interest_amount' => $this->interest_amount,
            'total_amount' => $this->total_amount,
            'outstanding_amount' => $this->outstanding_amount,
            'status' => $this->computedStatus($overdueService),
            'is_overdue' => $overdueService->isOverdue($this->resource),
            'days_overdue' => $overdueService->daysOverdue($this->resource),
            'grace_period_expires_at' => $overdueService->gracePeriodExpiresAt($this->resource)?->toDateString(),
            'penalties_accrued' => (string) ($this->penalties_sum_amount ?? '0.00'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Derives the display status (pending/due/overdue) relative to today
     * and the configured grace period. A schedule past its due date but
     * still within grace shows 'due', not 'overdue' — only
     * OverdueService::isOverdue() (due date + grace period elapsed,
     * balance still outstanding) earns the 'overdue' label. Statuses
     * already set by Payment Management (paid/partially_paid) are left
     * untouched since this module never writes them and they are
     * authoritative once present.
     */
    private function computedStatus(OverdueService $overdueService): string
    {
        if (in_array($this->status, self::AUTHORITATIVE_STATUSES, true)) {
            return $this->status;
        }

        $dueDate = $this->due_date;

        if ($dueDate === null) {
            return $this->status;
        }

        if ($overdueService->isOverdue($this->resource)) {
            return 'overdue';
        }

        if ($dueDate->isToday() || $dueDate->isPast()) {
            return 'due';
        }

        return 'pending';
    }
}
