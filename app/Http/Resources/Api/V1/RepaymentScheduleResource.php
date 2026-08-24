<?php

namespace App\Http\Resources\Api\V1;

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
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'installment_number' => $this->installment_number,
            'due_date' => $this->due_date?->toDateString(),
            'principal_amount' => $this->principal_amount,
            'interest_amount' => $this->interest_amount,
            'total_amount' => $this->total_amount,
            'outstanding_amount' => $this->outstanding_amount,
            'status' => $this->computedStatus(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Derives the display status (pending/due/overdue) from the due date
     * relative to today. Statuses already set by Payment Management
     * (paid/partially_paid) are left untouched since this module never
     * writes them and they are authoritative once present.
     */
    private function computedStatus(): string
    {
        if (in_array($this->status, self::AUTHORITATIVE_STATUSES, true)) {
            return $this->status;
        }

        $dueDate = $this->due_date;

        if ($dueDate === null) {
            return $this->status;
        }

        if ($dueDate->isToday()) {
            return 'due';
        }

        if ($dueDate->isPast()) {
            return 'overdue';
        }

        return 'pending';
    }
}
