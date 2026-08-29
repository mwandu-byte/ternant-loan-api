<?php

namespace App\Policies;

use App\Models\RepaymentSchedule;
use App\Models\User;
use App\Support\AccessScope;

class RepaymentSchedulePolicy
{
    public function view(User $user, RepaymentSchedule $repaymentSchedule): bool
    {
        return $user->can('repayment-schedules.view')
            && AccessScope::ownsOrUnrestricted($user, $repaymentSchedule->loan->created_by);
    }
}
