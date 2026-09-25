<?php

namespace App\Models;

use App\Models\Concerns\BusinessConfiguration;
use Database\Factories\RepaymentFrequencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'business_id',
    'name',
    'code',
    'interval_value',
    'interval_unit',
    'status',
])]
class RepaymentFrequency extends Model
{
    /** @use HasFactory<RepaymentFrequencyFactory> */
    use BusinessConfiguration, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_value' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
