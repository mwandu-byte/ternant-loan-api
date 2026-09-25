<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a loan-configuration model as owned by one business. Rows with a
 * NULL business_id are the platform default templates, copied into every
 * new business by LoanConfigurationProvisioner; they never apply to a
 * business's loans directly.
 *
 * A tenant user only ever sees and edits their own business's rows. A
 * platform user edits the templates. Loan logic never goes through the
 * acting user; it resolves rules with forBusiness() and the loan's own
 * business_id, so console runs and platform users get the same answer.
 */
trait BusinessConfiguration
{
    use BelongsToBusiness;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBusiness(Builder $query, ?int $businessId): Builder
    {
        return $businessId === null
            ? $query->whereNull($this->qualifyColumn('business_id'))
            : $query->where($this->qualifyColumn('business_id'), $businessId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $this->scopeForBusiness($query, $user->business_id);
    }

    /**
     * Another business's row (or a template, for a tenant user) is
     * indistinguishable from a missing one.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        $user = auth()->user();

        return ($user instanceof User ? $query->forUser($user) : $query)->first();
    }
}
