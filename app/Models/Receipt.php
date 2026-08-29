<?php

namespace App\Models;

use App\Support\AccessScope;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'receipt_no',
    'amount',
    'receipt_date',
    'payment_method',
    'reference_no',
    'received_by',
    'notes',
])]
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'receipt_date' => 'date',
        ];
    }

    /**
     * @param  Builder<Receipt>  $query
     * @return Builder<Receipt>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return AccessScope::restrictToOwnerOrActor($query, $user, 'received_by', 'repayments.loan');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
