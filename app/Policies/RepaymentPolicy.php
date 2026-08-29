<?php

namespace App\Policies;

use App\Models\Repayment;
use App\Models\User;
use App\Support\AccessScope;

class RepaymentPolicy
{
    public function view(User $user, Repayment $repayment): bool
    {
        return $user->can('repayments.view') && AccessScope::ownsActorOrLoanOrUnrestricted(
            $user,
            $repayment->received_by,
            $repayment->loan->created_by,
        );
    }
}
