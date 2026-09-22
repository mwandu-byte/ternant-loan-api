<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\AccessScope;
use Database\Factories\ApplicationFeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_id',
    'customer_id',
    'loan_id',
    'amount',
    'status',
    'paid_at',
    'payment_method',
    'reference_no',
    'receipt_no',
    'notes',
    'created_by',
])]
class ApplicationFee extends Model
{
    /** @use HasFactory<ApplicationFeeFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * Fees follow their customer's ownership: visible to whoever can see
     * the customer, and always within the user's business.
     *
     * @param  Builder<ApplicationFee>  $query
     * @return Builder<ApplicationFee>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query = AccessScope::restrictToBusiness($query, $user);

        return AccessScope::isUnrestricted($user)
            ? $query
            : $query->whereHas('customer', fn (Builder $q) => $q->where('created_by', $user->id));
    }

    /**
     * Paid and not yet consumed by a loan.
     *
     * @param  Builder<ApplicationFee>  $query
     * @return Builder<ApplicationFee>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'paid')->whereNull('loan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
