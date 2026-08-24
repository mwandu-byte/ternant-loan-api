<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $loan_id
 * @property Loan $loan
 * @property string $amount
 * @property Carbon $payment_date
 * @property string $payment_method
 * @property string|null $reference_no
 * @property string|null $notes
 * @property int $paid_by
 * @property Carbon $created_at
 */
class PaymentResource extends JsonResource
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
            'amount' => $this->amount,
            'payment_date' => $this->payment_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'notes' => $this->notes,
            'paid_by' => $this->paid_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
