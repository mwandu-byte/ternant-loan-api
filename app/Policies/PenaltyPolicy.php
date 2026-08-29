<?php

namespace App\Policies;

use App\Models\Penalty;
use App\Models\User;
use App\Support\AccessScope;

class PenaltyPolicy
{
    public function view(User $user, Penalty $penalty): bool
    {
        return $user->can('penalties.view') && AccessScope::ownsOrUnrestricted($user, $penalty->loan->created_by);
    }
}
