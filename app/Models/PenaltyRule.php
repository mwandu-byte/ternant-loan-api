<?php

namespace App\Models;

use Database\Factories\PenaltyRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'minimum_amount',
    'maximum_amount',
    'penalty_type',
    'penalty_value',
    'application_frequency',
    'status',
])]
class PenaltyRule extends Model
{
    /** @use HasFactory<PenaltyRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'penalty_value' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
