<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'full_name',
    'phone',
    'email',
    'identification_type',
    'identification_number',
    'gender',
    'address',
    'photo',
    'status',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function repayments(): HasManyThrough
    {
        return $this->hasManyThrough(Repayment::class, Loan::class);
    }

    public function repaymentSchedules(): HasManyThrough
    {
        return $this->hasManyThrough(RepaymentSchedule::class, Loan::class);
    }
}
