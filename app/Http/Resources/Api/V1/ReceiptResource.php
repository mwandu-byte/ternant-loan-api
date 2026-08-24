<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $receipt_no
 * @property string $amount
 * @property Carbon $receipt_date
 * @property string $payment_method
 * @property string|null $reference_no
 * @property int $received_by
 * @property string|null $notes
 * @property Carbon $created_at
 */
class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'amount' => $this->amount,
            'receipt_date' => $this->receipt_date?->toDateString(),
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'received_by' => $this->received_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
