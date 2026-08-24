<?php

namespace App\Models;

use Database\Factories\LoanAmountConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'minimum_amount',
    'maximum_amount',
])]
class LoanAmountConfiguration extends Model
{
    /** @use HasFactory<LoanAmountConfigurationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
        ];
    }
}
