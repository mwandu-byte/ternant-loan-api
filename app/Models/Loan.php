<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\AccessScope;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'business_id',
    'customer_id',
    'created_by',
    'reference_no',
    'principal_amount',
    'interest_rate',
    'interest_amount',
    'total_amount',
    'repayment_frequency',
    'repayment_term',
    'start_date',
    'due_date',
    'has_discount',
    'discount_rate',
    'applied_interest_rate',
    'status',
    'notes',
])]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToBusiness, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal_amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'repayment_term' => 'integer',
            'start_date' => 'date',
            'due_date' => 'date',
            'has_discount' => 'boolean',
            'discount_rate' => 'decimal:2',
            'applied_interest_rate' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictToOwner($query, $user);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function applicationFee(): HasOne
    {
        return $this->hasOne(ApplicationFee::class);
    }

    public function collaterals(): BelongsToMany
    {
        return $this->belongsToMany(Collateral::class, 'collateral_loan');
    }

    public function repaymentSchedules(): HasMany
    {
        return $this->hasMany(RepaymentSchedule::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    // A loan with no schedules at all must never count as "fully paid" —
    // only a loan that has gone through activation (which generates its
    // schedule) and had every installment settled to 'paid' qualifies.
    public function isFullyPaid(): bool
    {
        return $this->repaymentSchedules()->exists()
            && $this->repaymentSchedules()->where('status', '!=', 'paid')->doesntExist();
    }
}
