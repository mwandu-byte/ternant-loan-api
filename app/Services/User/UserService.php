<?php

namespace App\Services\User;

use App\Exceptions\User\SelfDeletionNotAllowedException;
use App\Exceptions\User\UserHasRelatedRecordsException;
use App\Models\User;
use App\Support\AdministrativeCoverageGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('is_enabled', $filters['status'] === 'active');
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $filters['role']));
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_enabled' => ($data['status'] ?? 'active') === 'active',
        ];

        try {
            $user = User::create($payload);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['email' => 'This email is already taken.']);
        }

        if (! empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user->load('roles');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $payload = collect($data)->only(['name', 'email', 'password'])->toArray();

        if (array_key_exists('status', $data)) {
            $payload['is_enabled'] = $data['status'] === 'active';
        }

        try {
            $user->update($payload);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['email' => 'This email is already taken.']);
        }

        return $user->load('roles');
    }

    public function delete(User $user, User $actingUser): void
    {
        if ($user->is($actingUser)) {
            throw new SelfDeletionNotAllowedException;
        }

        AdministrativeCoverageGuard::ensureRolesUpdateSurvives(excludeUser: $user);

        try {
            $user->delete();
        } catch (QueryException $e) {
            throw new UserHasRelatedRecordsException;
        }
    }

    /**
     * @param  array<int, string>  $roleNames
     */
    public function assignRoles(User $user, array $roleNames): User
    {
        $this->guardRolesUpdateSurvivesForUser($user, $roleNames);

        $user->syncRoles($roleNames);

        return $user->load('roles');
    }

    public function addRole(User $user, string $roleName): User
    {
        $user->assignRole($roleName);

        return $user->load('roles');
    }

    public function removeRole(User $user, string $roleName): User
    {
        $resultingRoles = $user->getRoleNames()
            ->reject(fn ($name) => $name === $roleName)
            ->values()
            ->all();

        $this->guardRolesUpdateSurvivesForUser($user, $resultingRoles);

        $user->removeRole($roleName);

        return $user->load('roles');
    }

    /**
     * @return Collection<int, string>
     */
    public function effectivePermissions(User $user): Collection
    {
        return $user->getAllPermissions()->pluck('name');
    }

    /**
     * Only runs the (relatively expensive) system-wide coverage check when
     * this specific mutation could plausibly remove the last roles.update
     * grant: the user currently holds it, and the new role set wouldn't.
     *
     * @param  array<int, string>  $newRoleNames
     */
    private function guardRolesUpdateSurvivesForUser(User $user, array $newRoleNames): void
    {
        $willHaveRolesUpdate = Role::whereIn('name', $newRoleNames)
            ->where('guard_name', 'api')
            ->get()
            ->contains(fn (Role $role) => $role->checkPermissionTo('roles.update'));

        if ($willHaveRolesUpdate || ! $user->can('roles.update')) {
            return;
        }

        AdministrativeCoverageGuard::ensureRolesUpdateSurvives(excludeUser: $user);
    }
}
