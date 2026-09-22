<?php

namespace App\Policies;

use App\Models\Guarantor;
use App\Models\User;
use App\Support\AccessScope;

class GuarantorPolicy
{
    public function view(User $user, Guarantor $guarantor): bool
    {
        return $user->can('guarantors.view') && $this->ownsLoan($user, $guarantor);
    }

    public function update(User $user, Guarantor $guarantor): bool
    {
        return $user->can('guarantors.update') && $this->ownsLoan($user, $guarantor);
    }

    public function delete(User $user, Guarantor $guarantor): bool
    {
        return $user->can('guarantors.delete') && $this->ownsLoan($user, $guarantor);
    }

    private function ownsLoan(User $user, Guarantor $guarantor): bool
    {
        return AccessScope::ownsOrUnrestricted($user, $guarantor->loan->created_by);
    }
}
