<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $full_name
 * @property string $phone
 * @property string|null $email
 * @property string $identification_type
 * @property string $identification_number
 * @property string|null $gender
 * @property string $address
 * @property string|null $photo
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'identification_type' => $this->identification_type,
            'identification_number' => $this->identification_number,
            'gender' => $this->gender,
            'address' => $this->address,
            'photo_url' => $this->photo
                ? Storage::disk(config('customer.photo_disk'))->url($this->photo)
                : null,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
