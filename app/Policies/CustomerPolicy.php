<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\AccessScope;

class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $user->can('customers.view') && AccessScope::ownsOrUnrestricted($user, $customer->created_by);
    }

    /**
     * Ownership-only check, with no customers.* permission requirement —
     * for nested resources (e.g. Collateral) that are gated by their own
     * permission domain and only need the customer-scope half enforced.
     */
    public function viewScope(User $user, Customer $customer): bool
    {
        return AccessScope::ownsOrUnrestricted($user, $customer->created_by);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can('customers.update') && AccessScope::ownsOrUnrestricted($user, $customer->created_by);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->can('customers.delete') && AccessScope::ownsOrUnrestricted($user, $customer->created_by);
    }
}
