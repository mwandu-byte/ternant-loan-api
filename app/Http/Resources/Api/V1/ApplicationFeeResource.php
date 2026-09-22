<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationFeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'loan_id' => $this->loan_id,
            'amount' => $this->amount,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toDateString(),
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'receipt_no' => $this->receipt_no,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
