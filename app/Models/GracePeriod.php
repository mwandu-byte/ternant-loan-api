<?php

namespace App\Models;

use App\Models\Concerns\BusinessConfiguration;
use Database\Factories\GracePeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'business_id',
    'duration',
    'unit',
    'status',
])]
class GracePeriod extends Model
{
    /** @use HasFactory<GracePeriodFactory> */
    use BusinessConfiguration, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
