<?php

namespace App\Models;

use App\Support\AccessScope;
use Database\Factories\RepaymentScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'loan_id',
    'installment_number',
    'due_date',
    'principal_amount',
    'interest_amount',
    'total_amount',
    'outstanding_amount',
    'status',
])]
class RepaymentSchedule extends Model
{
    /** @use HasFactory<RepaymentScheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'due_date' => 'date',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<RepaymentSchedule>  $query
     * @return Builder<RepaymentSchedule>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictViaLoan($query, $user);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }
}
