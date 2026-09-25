<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\RepaymentFrequency\StoreRepaymentFrequencyRequest;
use App\Http\Requests\Api\V1\RepaymentFrequency\UpdateRepaymentFrequencyRequest;
use App\Http\Resources\Api\V1\RepaymentFrequencyResource;
use App\Http\Responses\ApiResponse;
use App\Models\RepaymentFrequency;
use App\Services\LoanConfiguration\RepaymentFrequencyService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage repayment frequencies.
 *
 * A repayment frequency defines how often a loan's installments fall due
 * (e.g. monthly, every 2 months). Only active frequencies may be selected
 * when a loan is created. Codes are unique within a business.
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
class RepaymentFrequencyController extends Controller
{
    private const REPAYMENT_FREQUENCY_SCHEMA = 'array{id: int, name: string, code: string, interval_value: int, interval_unit: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly RepaymentFrequencyService $repaymentFrequencyService,
    ) {
        //
    }

    /**
     * List repayment frequencies
     *
     * Returns every configured repayment frequency, active and inactive.
     */
    #[Response(200, description: 'Repayment frequencies retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_FREQUENCY_SCHEMA.'[]}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            RepaymentFrequencyResource::collection($this->repaymentFrequencyService->list())->resolve(),
            'Repayment frequencies retrieved successfully',
        );
    }

    /**
     * Create repayment frequency
     *
     * Creates a new repayment frequency. `code` must be unique.
     */
    #[Response(201, description: 'Repayment frequency created.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_FREQUENCY_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (e.g. duplicate code).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['code' => ['The code has already been taken.']],
    ]])]
    public function store(StoreRepaymentFrequencyRequest $request): JsonResponse
    {
        $frequency = $this->repaymentFrequencyService->create($request->validated());

        return ApiResponse::success(new RepaymentFrequencyResource($frequency), 'Repayment frequency created successfully', 201);
    }

    /**
     * Show repayment frequency
     *
     * Returns a single repayment frequency.
     */
    #[Response(200, description: 'Repayment frequency retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_FREQUENCY_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment frequency does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(RepaymentFrequency $frequency): JsonResponse
    {
        return ApiResponse::success(new RepaymentFrequencyResource($frequency), 'Repayment frequency retrieved successfully');
    }

    /**
     * Update repayment frequency
     *
     * Updates a repayment frequency.
     */
    #[Response(200, description: 'Repayment frequency updated.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_FREQUENCY_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment frequency does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (e.g. duplicate code).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['code' => ['The code has already been taken.']],
    ]])]
    public function update(UpdateRepaymentFrequencyRequest $request, RepaymentFrequency $frequency): JsonResponse
    {
        $frequency = $this->repaymentFrequencyService->update($frequency, $request->validated());

        return ApiResponse::success(new RepaymentFrequencyResource($frequency), 'Repayment frequency updated successfully');
    }

    /**
     * Delete repayment frequency
     *
     * Permanently deletes a repayment frequency.
     */
    #[Response(200, description: 'Repayment frequency deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Repayment frequency deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment frequency does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function destroy(RepaymentFrequency $frequency): JsonResponse
    {
        $this->repaymentFrequencyService->delete($frequency);

        return ApiResponse::success(null, 'Repayment frequency deleted successfully');
    }
}
