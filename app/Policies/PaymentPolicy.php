<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\AccessScope;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.view') && AccessScope::ownsActorOrLoanOrUnrestricted(
            $user,
            $payment->paid_by,
            $payment->loan->created_by,
        );
    }
}
