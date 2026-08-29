<?php

namespace App\Models;

use App\Support\AccessScope;
use Database\Factories\PenaltyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'loan_id',
    'repayment_schedule_id',
    'penalty_rule_id',
    'amount',
    'period_start_date',
    'applied_date',
    'status',
    'reason',
])]
class Penalty extends Model
{
    /** @use HasFactory<PenaltyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start_date' => 'date',
            'applied_date' => 'date',
        ];
    }

    /**
     * @param  Builder<Penalty>  $query
     * @return Builder<Penalty>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictViaLoan($query, $user);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function repaymentSchedule(): BelongsTo
    {
        return $this->belongsTo(RepaymentSchedule::class);
    }

    public function penaltyRule(): BelongsTo
    {
        return $this->belongsTo(PenaltyRule::class);
    }
}
