<?php

namespace App\Models;

use App\Models\Concerns\BusinessConfiguration;
use Database\Factories\RepaymentTermFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'business_id',
    'name',
    'value',
    'unit',
    'status',
])]
class RepaymentTerm extends Model
{
    /** @use HasFactory<RepaymentTermFactory> */
    use BusinessConfiguration, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
