<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuarantorResource extends JsonResource
{
    /**
     * When the guarantor is an existing customer, identity fields are read
     * from that customer rather than duplicated on the guarantor row.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = $this->guarantor_customer_id && $this->relationLoaded('customer') ? $this->customer : $this->resource;

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'guarantor_customer_id' => $this->guarantor_customer_id,
            'full_name' => $source->full_name,
            'phone' => $source->phone,
            'identification_type' => $source->identification_type,
            'identification_number' => $source->identification_number,
            'address' => $source->address,
            'relationship' => $this->relationship,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
