<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\GracePeriod\UpdateGracePeriodRequest;
use App\Http\Resources\Api\V1\GracePeriodResource;
use App\Http\Responses\ApiResponse;
use App\Services\LoanConfiguration\GracePeriodService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage the grace period.
 *
 * The grace period is a single configuration value per business: the number
 * of days (or other unit) after a repayment's due date before it is
 * considered overdue. This module manages the configuration value only
 * — overdue detection and penalty processing are implemented separately.
 *
 * Loan configuration is per business. A business user sees and edits
 * only their own business's configuration; a platform user manages the
 * platform defaults that are copied into every new business. Another
 * business's rows are reported as not found (404).
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `loan-configurations.*` permission.
 */
#[Group('Loan Configuration')]
class GracePeriodController extends Controller
{
    private const GRACE_PERIOD_SCHEMA = 'array{id: int, duration: int, unit: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly GracePeriodService $gracePeriodService,
    ) {
        //
    }

    /**
     * Show grace period
     *
     * Returns the acting user's grace period configuration.
     */
    #[Response(200, description: 'Grace period retrieved.', type: 'array{success: true, message: string, data: '.self::GRACE_PERIOD_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function show(): JsonResponse
    {
        return ApiResponse::success(new GracePeriodResource($this->gracePeriodService->get()), 'Grace period retrieved successfully');
    }

    /**
     * Update grace period
     *
     * Updates the acting user's grace period configuration.
     */
    #[Response(200, description: 'Grace period updated.', type: 'array{success: true, message: string, data: '.self::GRACE_PERIOD_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['duration' => ['The duration field must be at least 1.']],
    ]])]
    public function update(UpdateGracePeriodRequest $request): JsonResponse
    {
        $gracePeriod = $this->gracePeriodService->update($request->validated());

        return ApiResponse::success(new GracePeriodResource($gracePeriod), 'Grace period updated successfully');
    }
}
