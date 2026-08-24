<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\RepaymentTerm\StoreRepaymentTermRequest;
use App\Http\Requests\Api\V1\RepaymentTerm\UpdateRepaymentTermRequest;
use App\Http\Resources\Api\V1\RepaymentTermResource;
use App\Http\Responses\ApiResponse;
use App\Models\RepaymentTerm;
use App\Services\LoanConfiguration\RepaymentTermService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage repayment terms.
 *
 * A repayment term defines an available loan duration (e.g. 3 months, 6
 * months). Only active terms may be selected when a loan is created.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `loan-configurations.*` permission.
 */
#[Group('Loan Configuration')]
class RepaymentTermController extends Controller
{
    private const REPAYMENT_TERM_SCHEMA = 'array{id: int, name: string, value: int, unit: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly RepaymentTermService $repaymentTermService,
    ) {
        //
    }

    /**
     * List repayment terms
     *
     * Returns every configured repayment term, active and inactive.
     */
    #[Response(200, description: 'Repayment terms retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_TERM_SCHEMA.'[]}')]
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
            RepaymentTermResource::collection($this->repaymentTermService->list())->resolve(),
            'Repayment terms retrieved successfully',
        );
    }

    /**
     * Create repayment term
     *
     * Creates a new repayment term.
     */
    #[Response(201, description: 'Repayment term created.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_TERM_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['value' => ['The value field is required.']],
    ]])]
    public function store(StoreRepaymentTermRequest $request): JsonResponse
    {
        $term = $this->repaymentTermService->create($request->validated());

        return ApiResponse::success(new RepaymentTermResource($term), 'Repayment term created successfully', 201);
    }

    /**
     * Show repayment term
     *
     * Returns a single repayment term.
     */
    #[Response(200, description: 'Repayment term retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_TERM_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment term does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(RepaymentTerm $term): JsonResponse
    {
        return ApiResponse::success(new RepaymentTermResource($term), 'Repayment term retrieved successfully');
    }

    /**
     * Update repayment term
     *
     * Updates a repayment term.
     */
    #[Response(200, description: 'Repayment term updated.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_TERM_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment term does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['value' => ['The value field is required.']],
    ]])]
    public function update(UpdateRepaymentTermRequest $request, RepaymentTerm $term): JsonResponse
    {
        $term = $this->repaymentTermService->update($term, $request->validated());

        return ApiResponse::success(new RepaymentTermResource($term), 'Repayment term updated successfully');
    }

    /**
     * Delete repayment term
     *
     * Permanently deletes a repayment term.
     */
    #[Response(200, description: 'Repayment term deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Repayment term deleted successfully',
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
    #[Response(404, description: 'Repayment term does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function destroy(RepaymentTerm $term): JsonResponse
    {
        $this->repaymentTermService->delete($term);

        return ApiResponse::success(null, 'Repayment term deleted successfully');
    }
}
