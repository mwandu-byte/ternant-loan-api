<?php

namespace App\Policies;

use App\Models\User;

/**
 * Users are gated by their users.* route permissions; tenant isolation is
 * enforced by the Gate::before hook, so once it passes there is nothing
 * further to restrict here.
 */
class UserPolicy
{
    public function access(User $user, User $target): bool
    {
        return true;
    }
}
