<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\AccessScope;
use Database\Factories\GuarantorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'loan_id',
    'guarantor_customer_id',
    'full_name',
    'phone',
    'identification_type',
    'identification_number',
    'address',
    'relationship',
    'created_by',
])]
class Guarantor extends Model
{
    /** @use HasFactory<GuarantorFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @param  Builder<Guarantor>  $query
     * @return Builder<Guarantor>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictViaLoan($query, $user);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'guarantor_customer_id');
    }
}
