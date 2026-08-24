<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Role\AddRolePermissionRequest;
use App\Http\Requests\Api\V1\Role\StoreRoleRequest;
use App\Http\Requests\Api\V1\Role\SyncRolePermissionsRequest;
use App\Http\Requests\Api\V1\Role\UpdateRoleRequest;
use App\Http\Resources\Api\V1\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Services\Role\RoleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Manage roles, using Spatie Laravel Permission as the single source of
 * truth (`Spatie\Permission\Models\Role`) — no separate roles table or
 * custom authorization implementation exists.
 *
 * Every role created or updated here always uses the application's `api`
 * guard; the guard can never be supplied by the client. Renaming a role
 * (`PUT /roles/{role}`) never touches its permissions — permission
 * assignment is a separate responsibility handled by the dedicated
 * `/roles/{role}/permissions` endpoints below. A role assigned to one or
 * more users cannot be deleted, and no operation here is ever allowed to
 * leave zero enabled users holding `roles.update` — the permission
 * needed to fix any authorization mistake.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `roles.*` permission.
 */
#[Group('Roles')]
class RoleController extends Controller
{
    private const PERMISSION_SCHEMA = 'array{id: int, name: string}';

    private const ROLE_SCHEMA = 'array{id: int, name: string, guard_name: string, permissions: '.self::PERMISSION_SCHEMA.'[], users_count: int, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly RoleService $roleService,
    ) {
        //
    }

    /**
     * List roles
     *
     * Returns a paginated list of roles for the `api` guard, including
     * their permissions and how many users currently hold each one.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Roles retrieved.', type: 'array{success: true, message: string, data: array{roles: '.self::ROLE_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->roleService->list($request->only(['per_page', 'page']));

        return ApiResponse::success([
            'roles' => RoleResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Roles retrieved successfully');
    }

    /**
     * Create a role
     *
     * Creates a new role for the `api` guard with no permissions
     * assigned. Use `POST/PUT /roles/{role}/permissions` afterward to
     * grant it permissions.
     */
    #[Response(201, description: 'Role created.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate role name).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['name' => ['This role name is already taken.']],
    ]])]
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return ApiResponse::success(
            new RoleResource($role->loadCount('users')),
            'Role created successfully',
            201,
        );
    }

    /**
     * Show a role
     *
     * Returns a single role, including its permissions and the number
     * of users currently holding it.
     */
    #[Response(200, description: 'Role retrieved.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(Role $role): JsonResponse
    {
        return ApiResponse::success(
            new RoleResource($role->load('permissions')->loadCount('users')),
            'Role retrieved successfully',
        );
    }

    /**
     * Update a role
     *
     * Renames a role. Never modifies its permissions — use
     * `PUT/POST/DELETE /roles/{role}/permissions` for that.
     */
    #[Response(200, description: 'Role updated.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate role name).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['name' => ['This role name is already taken.']],
    ]])]
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->update($role, $request->validated());

        return ApiResponse::success(
            new RoleResource($role->load('permissions')->loadCount('users')),
            'Role updated successfully',
        );
    }

    /**
     * Delete a role
     *
     * Blocked if the role is currently assigned to any user (no
     * auto-detachment), or if deleting it would leave zero enabled
     * users holding `roles.update`. Never deletes the underlying
     * Permission records — only this role and its permission
     * assignments.
     */
    #[Response(200, description: 'Role deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Role deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'The role is assigned to one or more users, or deleting it would leave zero enabled users able to manage roles.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This role cannot be deleted because it is assigned to one or more users.',
    ]])]
    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return ApiResponse::success(null, 'Role deleted successfully');
    }

    /**
     * Sync a role's permissions
     *
     * Replaces the role's complete permission set with the submitted
     * list of permission names. Permissions not included are removed.
     * Rejected if it would leave zero enabled users holding
     * `roles.update`.
     */
    #[Response(200, description: 'Permissions synced.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Would leave zero enabled users able to manage roles.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
    ]])]
    #[Response(422, description: 'One or more permission names do not exist for the api guard.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['permissions.0' => ['The selected permissions.0 is invalid.']],
    ]])]
    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->syncPermissions($role, $request->validated('permissions'));

        return ApiResponse::success(new RoleResource($role), 'Permissions synced successfully');
    }

    /**
     * Add a permission to a role
     *
     * Grants a single permission in addition to any the role already
     * has. Granting an already-held permission is a no-op.
     */
    #[Response(200, description: 'Permission added.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'The permission name does not exist for the api guard.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['permission' => ['The selected permission is invalid.']],
    ]])]
    public function addPermission(AddRolePermissionRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->addPermission($role, $request->validated('permission'));

        return ApiResponse::success(new RoleResource($role), 'Permission added successfully');
    }

    /**
     * Remove a permission from a role
     *
     * Removes only the relationship — the underlying Permission record
     * is untouched. Rejected if it would leave zero enabled users
     * holding `roles.update`.
     */
    #[Response(200, description: 'Permission removed.', type: 'array{success: true, message: string, data: '.self::ROLE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the roles.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Role or permission does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Would leave zero enabled users able to manage roles.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
    ]])]
    public function revokePermission(Role $role, Permission $permission): JsonResponse
    {
        $role = $this->roleService->revokePermission($role, $permission->name);

        return ApiResponse::success(new RoleResource($role), 'Permission removed successfully');
    }
}
