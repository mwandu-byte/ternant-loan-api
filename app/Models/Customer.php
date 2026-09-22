<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\AccessScope;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'business_id',
    'created_by',
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
    use BelongsToBusiness, HasFactory;

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictToOwner($query, $user);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }

    public function applicationFees(): HasMany
    {
        return $this->hasMany(ApplicationFee::class);
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
