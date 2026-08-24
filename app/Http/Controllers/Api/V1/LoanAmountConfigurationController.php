<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\LoanAmountConfiguration\UpdateLoanAmountConfigurationRequest;
use App\Http\Resources\Api\V1\LoanAmountConfigurationResource;
use App\Http\Responses\ApiResponse;
use App\Services\LoanConfiguration\LoanAmountConfigurationService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage the global loan amount configuration.
 *
 * This is the single, global minimum and maximum principal amount a loan
 * may be created for. A null maximum means there is no upper limit.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `loan-configurations.*` permission.
 */
#[Group('Loan Configuration')]
class LoanAmountConfigurationController extends Controller
{
    private const LOAN_AMOUNT_CONFIGURATION_SCHEMA = 'array{id: int, minimum_amount: string, maximum_amount: string|null, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly LoanAmountConfigurationService $loanAmountConfigurationService,
    ) {
        //
    }

    /**
     * Show loan amount configuration
     *
     * Returns the single, global loan amount configuration.
     */
    #[Response(200, description: 'Loan amount configuration retrieved.', type: 'array{success: true, message: string, data: '.self::LOAN_AMOUNT_CONFIGURATION_SCHEMA.'}')]
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
        return ApiResponse::success(new LoanAmountConfigurationResource($this->loanAmountConfigurationService->get()), 'Loan amount configuration retrieved successfully');
    }

    /**
     * Update loan amount configuration
     *
     * Updates the single, global loan amount configuration.
     */
    #[Response(200, description: 'Loan amount configuration updated.', type: 'array{success: true, message: string, data: '.self::LOAN_AMOUNT_CONFIGURATION_SCHEMA.'}')]
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
        'errors' => ['maximum_amount' => ['The maximum amount must be greater than the minimum amount.']],
    ]])]
    public function update(UpdateLoanAmountConfigurationRequest $request): JsonResponse
    {
        $configuration = $this->loanAmountConfigurationService->update($request->validated());

        return ApiResponse::success(new LoanAmountConfigurationResource($configuration), 'Loan amount configuration updated successfully');
    }
}
