<?php

namespace App\Services\Permission;

use App\Exceptions\Permission\PermissionInUseException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class PermissionService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Permission::query()->where('guard_name', 'api');

        $query->orderBy('name');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Permission
    {
        try {
            return Permission::create(['name' => $data['name'], 'guard_name' => 'api']);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['name' => 'This permission name is already taken.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Permission $permission, array $data): Permission
    {
        try {
            $permission->update(['name' => $data['name']]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['name' => 'This permission name is already taken.']);
        }

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        if ($permission->roles()->exists() || $permission->users()->exists()) {
            throw new PermissionInUseException;
        }

        $permission->delete();
    }
}
