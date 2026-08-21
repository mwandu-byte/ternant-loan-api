<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RefreshTokenRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\ChangePasswordService;
use App\Services\Auth\ForgotPasswordService;
use App\Services\Auth\ResetPasswordService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Login, refresh, logout, current-user, and password-management endpoints.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * Endpoints under the `auth:api` guard require `Authorization: Bearer {access_token}`.
 */
#[Group('Authentication')]
class AuthController extends Controller
{
    private const USER_SCHEMA = 'array{id: int, name: string, email: string, email_verified_at: string|null, created_at: string, updated_at: string, roles: string[]}';

    private const TOKEN_DATA_SCHEMA = 'array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: '.self::USER_SCHEMA.'}';

    private const NULL_DATA_SCHEMA = 'array{success: true, message: string, data: null}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    private const RATE_LIMITED_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly AuthService $authService,
        private readonly ForgotPasswordService $forgotPasswordService,
        private readonly ResetPasswordService $resetPasswordService,
        private readonly ChangePasswordService $changePasswordService,
    ) {
        //
    }

    /**
     * Login
     *
     * Authenticates a user with email + password and issues a short-lived JWT
     * access token plus a long-lived, rotating, revocable refresh token.
     */
    #[Response(200, description: 'Login successful.', type: 'array{success: true, message: string, data: '.self::TOKEN_DATA_SCHEMA.'}', examples: [[
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
            'refresh_token' => 'E4XzsimVrZBKazG0BxsPuPBgudVk4jVz0fvQkdJWmLBweGs12zA2oo51CxSTQ8yn',
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'user' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'email_verified_at' => '2026-08-21T12:21:05.000000Z',
                'created_at' => '2026-08-21T12:21:05.000000Z',
                'updated_at' => '2026-08-21T12:21:05.000000Z',
                'roles' => ['admin'],
            ],
        ],
    ]])]
    #[Response(401, description: 'Email/password combination is incorrect.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Invalid credentials.',
    ]])]
    #[Response(422, description: 'Missing or malformed email/password.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['email' => ['The email field is required.'], 'password' => ['The password field is required.']],
    ]])]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        return ApiResponse::success([
            ...$result,
            'user' => $this->userPayload($result['user']),
        ], 'Login successful');
    }

    /**
     * Refresh access token
     *
     * Exchanges a valid, unexpired, unrevoked refresh token for a brand new
     * access token + refresh token pair. The refresh token supplied in the
     * request is rotated: it is revoked immediately and cannot be reused. If
     * an already-revoked or expired refresh token is submitted, every active
     * refresh token for that user is revoked as a compromise response.
     */
    #[Response(200, description: 'Token refreshed successfully.', type: 'array{success: true, message: string, data: '.self::TOKEN_DATA_SCHEMA.'}', examples: [[
        'success' => true,
        'message' => 'Token refreshed successfully',
        'data' => [
            'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
            'refresh_token' => '1f9PHjt5TsOUtviJFP54jN2dh98TLhjMzrAnewSSkYfzOpQQZj8kfFAOZitovZc0',
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'user' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'email_verified_at' => '2026-08-21T12:21:05.000000Z',
                'created_at' => '2026-08-21T12:21:05.000000Z',
                'updated_at' => '2026-08-21T12:21:05.000000Z',
                'roles' => ['admin'],
            ],
        ],
    ]])]
    #[Response(401, description: 'The refresh token does not exist, has expired, or has already been used/revoked.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Invalid or expired refresh token.',
    ]])]
    #[Response(422, description: 'Missing refresh_token field.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['refresh_token' => ['The refresh token field is required.']],
    ]])]
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->authService->refresh($request->validated('refresh_token'));

        return ApiResponse::success([
            ...$result,
            'user' => $this->userPayload($result['user']),
        ], 'Token refreshed successfully');
    }

    /**
     * Logout
     *
     * Revokes every active refresh token for the authenticated user and
     * blacklists the current JWT access token so it can no longer be used.
     *
     * Requires `Authorization: Bearer {access_token}`.
     */
    #[Response(200, description: 'Logged out successfully.', type: self::NULL_DATA_SCHEMA, examples: [[
        'success' => true,
        'message' => 'Logged out successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Logged out successfully');
    }

    /**
     * Get authenticated user
     *
     * Returns the authenticated user's profile, assigned roles, and the
     * full list of permissions resolved through those roles.
     *
     * Requires `Authorization: Bearer {access_token}`.
     */
    #[Response(200, description: 'Authenticated user retrieved.', type: 'array{success: true, message: string, data: array{user: '.self::USER_SCHEMA.', permissions: string[]}}', examples: [[
        'success' => true,
        'message' => 'User retrieved successfully',
        'data' => [
            'user' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'email_verified_at' => '2026-08-21T12:21:05.000000Z',
                'created_at' => '2026-08-21T12:21:05.000000Z',
                'updated_at' => '2026-08-21T12:21:05.000000Z',
                'roles' => ['admin'],
            ],
            'permissions' => ['customers.view', 'loans.view', 'loans.create'],
        ],
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success([
            'user' => $this->userPayload($user),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ], 'User retrieved successfully');
    }

    /**
     * Forgot password
     *
     * Always responds with the same generic success message whether or not
     * the email belongs to an account, so the endpoint cannot be used to
     * enumerate registered users. If the email exists, a password reset
     * email containing a mobile deep link is sent. Rate limited per IP.
     */
    #[Response(200, description: 'Always returned, regardless of whether the email exists.', type: self::NULL_DATA_SCHEMA, examples: [[
        'success' => true,
        'message' => 'If the account exists, a password reset instruction has been sent.',
        'data' => null,
    ]])]
    #[Response(422, description: 'Malformed email.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['email' => ['The email field must be a valid email address.']],
    ]])]
    #[Response(429, description: 'Too many requests from this IP.', type: self::RATE_LIMITED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Too many requests. Please try again later.',
    ]])]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->forgotPasswordService->sendResetLink($request->validated('email'));

        return ApiResponse::success(null, 'If the account exists, a password reset instruction has been sent.');
    }

    /**
     * Reset password
     *
     * Resets the account's password using the token emailed by
     * forgot-password. The token is single-use and is invalidated after a
     * successful reset (or after any new forgot-password request for the
     * same account). On success, every active refresh token for the user is
     * revoked, so previously issued sessions can no longer refresh — the
     * user is not automatically logged in and must call login again.
     */
    #[Response(200, description: 'Password reset successfully.', type: self::NULL_DATA_SCHEMA, examples: [[
        'success' => true,
        'message' => 'Password reset successfully.',
        'data' => null,
    ]])]
    #[Response(422, description: 'Either the token does not exist / has expired / was already used, or the request failed validation (password confirmation mismatch, password too weak, etc). The "invalid or expired" case always returns an empty errors object; validation failures return field-keyed errors.', type: 'array{success: false, message: string, errors: object|array<string, string[]>}', examples: [
        [
            'success' => false,
            'message' => 'The password reset token is invalid or has expired.',
            'errors' => [],
        ],
        [
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => ['password' => ['The password field confirmation does not match.']],
        ],
    ])]
    #[Response(429, description: 'Too many requests from this IP.', type: self::RATE_LIMITED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Too many requests. Please try again later.',
    ]])]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->resetPasswordService->reset(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Password reset successfully.');
    }

    /**
     * Change password
     *
     * Changes the authenticated user's password after verifying the
     * supplied current password. The new password must differ from the
     * current one. On success, every active refresh token for the user is
     * revoked — other logged-in sessions must log in again; the current
     * access token used to make this request remains valid until it expires
     * naturally.
     *
     * Requires `Authorization: Bearer {access_token}`.
     */
    #[Response(200, description: 'Password changed successfully.', type: self::NULL_DATA_SCHEMA, examples: [[
        'success' => true,
        'message' => 'Password changed successfully.',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(422, description: 'Either current_password does not match the account\'s actual password, or the request failed validation (new password same as current, confirmation mismatch, password too weak, etc).', type: self::VALIDATION_ERROR_SCHEMA, examples: [
        [
            'success' => false,
            'message' => 'The current password is incorrect.',
            'errors' => ['current_password' => ['The current password is incorrect.']],
        ],
        [
            'success' => false,
            'message' => 'The given data was invalid.',
            'errors' => ['password' => ['The password field and current password must be different.']],
        ],
    ])]
    #[Response(429, description: 'Too many requests from this user.', type: self::RATE_LIMITED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Too many requests. Please try again later.',
    ]])]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->changePasswordService->change(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, 'Password changed successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            ...$user->toArray(),
            'roles' => $user->getRoleNames(),
        ];
    }
}
