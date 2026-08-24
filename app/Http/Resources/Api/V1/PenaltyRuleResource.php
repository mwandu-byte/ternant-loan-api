<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $minimum_amount
 * @property string|null $maximum_amount
 * @property string $penalty_type
 * @property string $penalty_value
 * @property string $application_frequency
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PenaltyRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'minimum_amount' => $this->minimum_amount,
            'maximum_amount' => $this->maximum_amount,
            'penalty_type' => $this->penalty_type,
            'penalty_value' => $this->penalty_value,
            'application_frequency' => $this->application_frequency,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
