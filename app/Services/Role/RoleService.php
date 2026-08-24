<?php

namespace App\Services\Role;

use App\Exceptions\Role\RoleAssignedToUsersException;
use App\Models\User;
use App\Support\AdministrativeCoverageGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Role::query()->where('guard_name', 'api')->with('permissions')->withCount('users');

        $query->orderBy('name');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Role
    {
        try {
            return Role::create(['name' => $data['name'], 'guard_name' => 'api']);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['name' => 'This role name is already taken.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        try {
            $role->update(['name' => $data['name']]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['name' => 'This role name is already taken.']);
        }

        return $role;
    }

    public function delete(Role $role): void
    {
        if ($role->users()->exists()) {
            throw new RoleAssignedToUsersException;
        }

        // Structurally always passes given the check above already
        // guarantees zero holders — kept for defense-in-depth and
        // consistency with the other four call sites of this guard.
        AdministrativeCoverageGuard::ensureRolesUpdateSurvives();

        $role->delete();
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function syncPermissions(Role $role, array $permissionNames): Role
    {
        $currentlyHasRolesUpdate = $role->permissions->contains('name', 'roles.update');
        $willHaveRolesUpdate = in_array('roles.update', $permissionNames, true);

        if ($currentlyHasRolesUpdate && ! $willHaveRolesUpdate) {
            $this->guardRoleLosingRolesUpdate($role);
        }

        $role->syncPermissions($permissionNames);

        return $role->load('permissions')->loadCount('users');
    }

    public function addPermission(Role $role, string $permissionName): Role
    {
        $role->givePermissionTo($permissionName);

        return $role->load('permissions')->loadCount('users');
    }

    public function revokePermission(Role $role, string $permissionName): Role
    {
        if ($permissionName === 'roles.update' && $role->permissions->contains('name', 'roles.update')) {
            $this->guardRoleLosingRolesUpdate($role);
        }

        $role->revokePermissionTo($permissionName);

        return $role->load('permissions')->loadCount('users');
    }

    /**
     * Simulates this role losing roles.update: for each enabled user
     * holding the role, checks whether they'd still have roles.update via
     * a direct grant or another role. Users who don't hold this role are
     * unaffected and checked against their real, current permission.
     */
    private function guardRoleLosingRolesUpdate(Role $role): void
    {
        AdministrativeCoverageGuard::ensureRolesUpdateSurvives(
            simulate: function (User $user) use ($role) {
                if (! $user->hasRole($role)) {
                    return $user->can('roles.update');
                }

                if ($user->permissions->contains('name', 'roles.update')) {
                    return true;
                }

                return $user->roles
                    ->reject(fn ($r) => $r->is($role))
                    ->contains(fn ($r) => $r->checkPermissionTo('roles.update'));
            },
        );
    }
}
