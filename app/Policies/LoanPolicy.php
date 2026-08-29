<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use App\Support\AccessScope;

class LoanPolicy
{
    public function view(User $user, Loan $loan): bool
    {
        return $user->can('loans.view') && AccessScope::ownsOrUnrestricted($user, $loan->created_by);
    }

    /**
     * Ownership-only check, with no loans.* permission requirement — for
     * nested actions (e.g. generating a schedule, disbursing) that are
     * gated by their own permission domain and only need the loan-scope
     * half enforced.
     */
    public function viewScope(User $user, Loan $loan): bool
    {
        return AccessScope::ownsOrUnrestricted($user, $loan->created_by);
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->can('loans.update') && AccessScope::ownsOrUnrestricted($user, $loan->created_by);
    }

    public function delete(User $user, Loan $loan): bool
    {
        return $user->can('loans.delete') && AccessScope::ownsOrUnrestricted($user, $loan->created_by);
    }
}
