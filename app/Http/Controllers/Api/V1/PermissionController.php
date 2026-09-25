<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Permission\StorePermissionRequest;
use App\Http\Requests\Api\V1\Permission\UpdatePermissionRequest;
use App\Http\Resources\Api\V1\PermissionResource;
use App\Http\Responses\ApiResponse;
use App\Services\Permission\PermissionService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * Manage permissions, using Spatie Laravel Permission as the single
 * source of truth (`Spatie\Permission\Models\Permission`) — no separate
 * permissions table exists.
 *
 * Every permission created or updated here always uses the
 * application's `api` guard; the guard can never be supplied by the
 * client. A permission currently assigned to any role, or granted
 * directly to any user, cannot be deleted — only unused permissions may
 * be removed. To attach or detach a permission from a role, use the
 * `/roles/{role}/permissions` endpoints in the Roles group; this group
 * only manages Permission records themselves.
 *
 * Permissions are shared by every business, so creating, updating or deleting
 * them is limited to platform users (users attached to no business);
 * business users get 403 on those endpoints and can only read.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `permissions.*` permission.
 */
#[Group('Permissions')]
class PermissionController extends Controller
{
    private const PERMISSION_SCHEMA = 'array{id: int, name: string, guard_name: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly PermissionService $permissionService,
    ) {
        //
    }

    /**
     * List permissions
     *
     * Returns a paginated list of permissions for the `api` guard.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Permissions retrieved.', type: 'array{success: true, message: string, data: array{permissions: '.self::PERMISSION_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the permissions.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->permissionService->list($request->only(['per_page', 'page']));

        return ApiResponse::success([
            'permissions' => PermissionResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Permissions retrieved successfully');
    }

    /**
     * Create a permission
     *
     * Creates a new permission for the `api` guard.
     */
    #[Response(201, description: 'Permission created.', type: 'array{success: true, message: string, data: '.self::PERMISSION_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the permissions.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate permission name).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['name' => ['This permission name is already taken.']],
    ]])]
    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = $this->permissionService->create($request->validated());

        return ApiResponse::success(
            new PermissionResource($permission),
            'Permission created successfully',
            201,
        );
    }

    /**
     * Show a permission
     */
    #[Response(200, description: 'Permission retrieved.', type: 'array{success: true, message: string, data: '.self::PERMISSION_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the permissions.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Permission does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(Permission $permission): JsonResponse
    {
        return ApiResponse::success(new PermissionResource($permission), 'Permission retrieved successfully');
    }

    /**
     * Update a permission
     *
     * Renames a permission.
     */
    #[Response(200, description: 'Permission updated.', type: 'array{success: true, message: string, data: '.self::PERMISSION_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the permissions.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Permission does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate permission name).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['name' => ['This permission name is already taken.']],
    ]])]
    public function update(UpdatePermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission = $this->permissionService->update($permission, $request->validated());

        return ApiResponse::success(new PermissionResource($permission), 'Permission updated successfully');
    }

    /**
     * Delete a permission
     *
     * Blocked if the permission is currently assigned to any role, or
     * granted directly to any user.
     */
    #[Response(200, description: 'Permission deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Permission deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the permissions.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Permission does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'The permission is currently in use.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This permission cannot be deleted because it is currently in use.',
    ]])]
    public function destroy(Permission $permission): JsonResponse
    {
        $this->permissionService->delete($permission);

        return ApiResponse::success(null, 'Permission deleted successfully');
    }
}
