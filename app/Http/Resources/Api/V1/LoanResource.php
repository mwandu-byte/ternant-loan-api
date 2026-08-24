<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $reference_no
 * @property string $principal_amount
 * @property string $interest_rate
 * @property string $interest_amount
 * @property string $total_amount
 * @property bool $has_discount
 * @property string|null $discount_rate
 * @property string $applied_interest_rate
 * @property string $repayment_frequency
 * @property int $repayment_term
 * @property Carbon $start_date
 * @property Carbon $due_date
 * @property string $status
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'reference_no' => $this->reference_no,
            'principal_amount' => $this->principal_amount,
            'interest_rate' => $this->interest_rate,
            'interest_amount' => $this->interest_amount,
            'total_amount' => $this->total_amount,
            'has_discount' => $this->has_discount,
            'discount_rate' => $this->discount_rate,
            'applied_interest_rate' => $this->applied_interest_rate,
            'repayment_frequency' => $this->repayment_frequency,
            'repayment_term' => $this->repayment_term,
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'collaterals' => CollateralResource::collection($this->collaterals),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
