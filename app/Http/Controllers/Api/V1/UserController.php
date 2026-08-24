<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\User\SelfRoleModificationException;
use App\Http\Requests\Api\V1\User\AddUserRoleRequest;
use App\Http\Requests\Api\V1\User\StoreUserRequest;
use App\Http\Requests\Api\V1\User\SyncUserRolesRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\User\UserService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Manage system users — the people who operate this application.
 *
 * System users are completely separate from Customers: Customers are
 * business borrowers who never authenticate and have no roles or
 * permissions; Users authenticate via the existing JWT auth system and
 * get their permissions entirely through assigned Roles.
 *
 * Role assignment is a distinct responsibility from editing a user's
 * own fields — `PUT /users/{user}` never changes roles, and the
 * dedicated `/users/{user}/roles` endpoints below never touch name,
 * email, password, or status. A user can never modify their own role
 * assignment through those endpoints, even while holding
 * `users.update` — it must be done by a different authorized user, to
 * prevent self-escalation. Deleting a user is blocked outright for
 * your own account, and is also blocked for any account if it would
 * leave zero enabled users holding `roles.update` (the permission
 * needed to fix any authorization mistake).
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `users.*` permission.
 */
#[Group('Users')]
class UserController extends Controller
{
    private const ROLE_SCHEMA = 'array{id: int, name: string}';

    private const USER_SCHEMA = 'array{id: int, name: string, email: string, status: string, roles: '.self::ROLE_SCHEMA.'[], created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly UserService $userService,
    ) {
        //
    }

    /**
     * List users
     *
     * Returns a paginated, searchable, filterable list of system users.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('search', description: 'Matches against name and email.', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status.', type: 'string', example: 'active')]
    #[QueryParameter('role', description: 'Filter by assigned role name.', type: 'string', example: 'admin')]
    #[Response(200, description: 'Users retrieved.', type: 'array{success: true, message: string, data: array{users: '.self::USER_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userService->list($request->only([
            'search', 'status', 'role', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'users' => UserResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Users retrieved successfully');
    }

    /**
     * Create a user
     *
     * Creates a new system user. `roles` (an array of role names) may
     * optionally be supplied to assign roles at creation time — this is
     * the only endpoint where role assignment and other fields are set
     * together; every other role change goes through the dedicated
     * `/users/{user}/roles` endpoints. The password is always securely
     * hashed and is never returned in any response.
     */
    #[Response(201, description: 'User created.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate email, weak password, or unknown role name).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['email' => ['This email is already taken.']],
    ]])]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return ApiResponse::success(
            new UserResource($user),
            'User created successfully',
            201,
        );
    }

    /**
     * Show a user
     *
     * Returns a single user, including their assigned roles. Never
     * returns the password, password hash, or any authentication token.
     */
    #[Response(200, description: 'User retrieved.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user->load('roles')), 'User retrieved successfully');
    }

    /**
     * Update a user
     *
     * Updates name, email, status, and/or password. Password is only
     * changed if explicitly supplied. Roles are never changed by this
     * endpoint, even if a `roles` field is included in the request body
     * — use the dedicated `/users/{user}/roles` endpoints instead.
     */
    #[Response(200, description: 'User updated.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate email or weak password).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['email' => ['This email is already taken.']],
    ]])]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated());

        return ApiResponse::success(new UserResource($user), 'User updated successfully');
    }

    /**
     * Delete a user
     *
     * Permanently deletes a system user. Blocked if the target is the
     * currently authenticated user (self-deletion is never allowed), if
     * deleting them would leave zero enabled users holding
     * `roles.update`, or if they have related financial records (loan
     * disbursements or recorded repayments).
     */
    #[Response(200, description: 'User deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'User deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Deleting this user would leave zero enabled users able to manage roles, or the user has related financial records.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
    ]])]
    #[Response(422, description: 'Attempting to delete your own account.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'You cannot delete your own account.',
    ]])]
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->delete($user, $request->user());

        return ApiResponse::success(null, 'User deleted successfully');
    }

    /**
     * Sync a user's roles
     *
     * Replaces the user's complete role set with the submitted list.
     * Roles not included are removed. Cannot be used on your own
     * account. Rejected if it would leave zero enabled users holding
     * `roles.update`.
     */
    #[Response(200, description: 'Roles synced.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.update permission, or attempting to modify your own role assignment.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You cannot modify your own role assignments.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Would leave zero enabled users able to manage roles.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
    ]])]
    #[Response(422, description: 'One or more role names do not exist for the api guard.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['roles.0' => ['The selected roles.0 is invalid.']],
    ]])]
    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw new SelfRoleModificationException;
        }

        $user = $this->userService->assignRoles($user, $request->validated('roles'));

        return ApiResponse::success(new UserResource($user), 'Roles synced successfully');
    }

    /**
     * Add a role to a user
     *
     * Assigns a single role in addition to any the user already has.
     * Assigning an already-held role is a no-op. Cannot be used on your
     * own account.
     */
    #[Response(200, description: 'Role added.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.update permission, or attempting to modify your own role assignment.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You cannot modify your own role assignments.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'The role name does not exist for the api guard.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['role' => ['The selected role is invalid.']],
    ]])]
    public function addRole(AddUserRoleRequest $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            throw new SelfRoleModificationException;
        }

        $user = $this->userService->addRole($user, $request->validated('role'));

        return ApiResponse::success(new UserResource($user), 'Role added successfully');
    }

    /**
     * Remove a role from a user
     *
     * Removes only the relationship — the role itself is untouched.
     * Cannot be used on your own account. Rejected if it would leave
     * zero enabled users holding `roles.update`.
     */
    #[Response(200, description: 'Role removed.', type: 'array{success: true, message: string, data: '.self::USER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.update permission, or attempting to modify your own role assignment.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You cannot modify your own role assignments.',
    ]])]
    #[Response(404, description: 'User or role does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Would leave zero enabled users able to manage roles.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
    ]])]
    public function removeRole(Request $request, User $user, Role $role): JsonResponse
    {
        if ($user->is($request->user())) {
            throw new SelfRoleModificationException;
        }

        $user = $this->userService->removeRole($user, $role->name);

        return ApiResponse::success(new UserResource($user), 'Role removed successfully');
    }

    /**
     * Effective permissions of a user
     *
     * Returns the user's complete effective permission set — everything
     * granted through their assigned roles, plus any permission granted
     * directly to them.
     */
    #[Response(200, description: 'Permissions retrieved.', type: 'array{success: true, message: string, data: array{permissions: string[]}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the users.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'User does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function permissions(User $user): JsonResponse
    {
        return ApiResponse::success([
            'permissions' => $this->userService->effectivePermissions($user),
        ], 'Permissions retrieved successfully');
    }
}
