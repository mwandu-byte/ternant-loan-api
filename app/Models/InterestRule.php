<?php

namespace App\Models;

use App\Models\Concerns\BusinessConfiguration;
use Database\Factories\InterestRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'business_id',
    'minimum_amount',
    'maximum_amount',
    'interest_rate',
    'calculation_method',
    'status',
])]
class InterestRule extends Model
{
    /** @use HasFactory<InterestRuleFactory> */
    use BusinessConfiguration, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
