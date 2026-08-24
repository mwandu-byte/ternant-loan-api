<?php

namespace App\Support;

use App\Exceptions\Authorization\InsufficientAdministrativeCoverageException;
use App\Models\User;
use Closure;

final class AdministrativeCoverageGuard
{
    /**
     * Ensures at least one enabled user (other than $excludeUser, if given)
     * would still hold the `roles.update` permission — the permission
     * needed to fix any authorization mistake — after a mutation. Uses
     * Spatie's real permission resolution rather than querying the raw
     * pivot tables, so both role-based and any direct grants are honored.
     *
     * @param  Closure(User): bool|null  $simulate  Given a candidate user,
     *                                              returns whether they would still hold roles.update under a
     *                                              hypothetical end-state (e.g. a role's permissions after a
     *                                              sync). Defaults to the user's real, current permission.
     */
    public static function ensureRolesUpdateSurvives(?User $excludeUser = null, ?Closure $simulate = null): void
    {
        $check = $simulate ?? fn (User $user) => $user->can('roles.update');

        $survives = User::where('is_enabled', true)
            ->when($excludeUser, fn ($query) => $query->whereKeyNot($excludeUser->id))
            ->get()
            ->contains($check);

        if (! $survives) {
            throw new InsufficientAdministrativeCoverageException;
        }
    }
}
