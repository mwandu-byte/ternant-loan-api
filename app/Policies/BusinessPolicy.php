<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;
use App\Support\AccessScope;

/**
 * Only platform users manage the set of businesses. A tenant user may
 * read (never modify) their own business; the Gate::before hook already
 * denies any other business to them.
 */
class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessScope::isPlatformUser($user) && $user->can('businesses.view');
    }

    public function view(User $user, Business $business): bool
    {
        return $user->can('businesses.view');
    }

    public function create(User $user): bool
    {
        return AccessScope::isPlatformUser($user) && $user->can('businesses.create');
    }

    public function update(User $user, Business $business): bool
    {
        return AccessScope::isPlatformUser($user) && $user->can('businesses.update');
    }
}
