<?php

namespace App\Policies;

use App\Models\ApplicationFee;
use App\Models\User;
use App\Support\AccessScope;

class ApplicationFeePolicy
{
    public function view(User $user, ApplicationFee $fee): bool
    {
        return $user->can('application-fees.view')
            && AccessScope::ownsOrUnrestricted($user, $fee->customer->created_by);
    }
}
