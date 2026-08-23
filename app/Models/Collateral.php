<?php

namespace App\Models;

use Database\Factories\CollateralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'customer_id',
    'type',
    'description',
    'estimated_value',
    'status',
])]
class Collateral extends Model
{
    /** @use HasFactory<CollateralFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loans(): BelongsToMany
    {
        return $this->belongsToMany(Loan::class, 'collateral_loan');
    }
}
