<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Loan;
use App\Models\Receipt;
use App\Models\RepaymentSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $loan_id
 * @property Loan $loan
 * @property int $repayment_schedule_id
 * @property RepaymentSchedule $repaymentSchedule
 * @property int $receipt_id
 * @property Receipt $receipt
 * @property string $amount
 * @property Carbon $repayment_date
 * @property string|null $notes
 * @property int $received_by
 * @property Carbon $created_at
 */
class RepaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'loan' => new LoanResource($this->whenLoaded('loan')),
            'repayment_schedule_id' => $this->repayment_schedule_id,
            'repayment_schedule' => new RepaymentScheduleResource($this->whenLoaded('repaymentSchedule')),
            'receipt_id' => $this->receipt_id,
            'receipt' => new ReceiptResource($this->whenLoaded('receipt')),
            'amount' => $this->amount,
            'repayment_date' => $this->repayment_date?->toDateString(),
            'notes' => $this->notes,
            'received_by' => $this->received_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
