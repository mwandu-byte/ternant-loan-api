<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $loan_id
 * @property int $repayment_schedule_id
 * @property int|null $penalty_rule_id
 * @property string $amount
 * @property \Illuminate\Support\Carbon $period_start_date
 * @property \Illuminate\Support\Carbon $applied_date
 * @property string $status
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class PenaltyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'repayment_schedule_id' => $this->repayment_schedule_id,
            'penalty_rule_id' => $this->penalty_rule_id,
            'amount' => $this->amount,
            'period_start_date' => $this->period_start_date?->toDateString(),
            'applied_date' => $this->applied_date?->toDateString(),
            'status' => $this->status,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
