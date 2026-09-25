<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\PenaltyRule\StorePenaltyRuleRequest;
use App\Http\Requests\Api\V1\PenaltyRule\UpdatePenaltyRuleRequest;
use App\Http\Resources\Api\V1\PenaltyRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\PenaltyRule;
use App\Services\LoanConfiguration\PenaltyRuleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage penalty rules.
 *
 * Penalty rules define, by outstanding loan amount range, the penalty
 * charged and how often it is applied once a repayment is overdue. This
 * module manages the rules only — calculating and applying penalties to
 * an actual overdue loan is implemented separately.
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
class PenaltyRuleController extends Controller
{
    private const PENALTY_RULE_SCHEMA = 'array{id: int, minimum_amount: string, maximum_amount: string|null, penalty_type: string, penalty_value: string, application_frequency: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly PenaltyRuleService $penaltyRuleService,
    ) {
        //
    }

    /**
     * List penalty rules
     *
     * Returns every configured penalty rule, active and inactive.
     */
    #[Response(200, description: 'Penalty rules retrieved.', type: 'array{success: true, message: string, data: '.self::PENALTY_RULE_SCHEMA.'[]}')]
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
            PenaltyRuleResource::collection($this->penaltyRuleService->list())->resolve(),
            'Penalty rules retrieved successfully',
        );
    }

    /**
     * Create penalty rule
     *
     * Creates a new penalty rule. The amount range of an active rule must
     * not overlap the range of any other active rule.
     */
    #[Response(201, description: 'Penalty rule created.', type: 'array{success: true, message: string, data: '.self::PENALTY_RULE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (invalid range, or the amount range overlaps an existing active rule).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['minimum_amount' => ['This amount range overlaps with an existing active penalty rule.']],
    ]])]
    public function store(StorePenaltyRuleRequest $request): JsonResponse
    {
        $penaltyRule = $this->penaltyRuleService->create($request->validated());

        return ApiResponse::success(new PenaltyRuleResource($penaltyRule), 'Penalty rule created successfully', 201);
    }

    /**
     * Show penalty rule
     *
     * Returns a single penalty rule.
     */
    #[Response(200, description: 'Penalty rule retrieved.', type: 'array{success: true, message: string, data: '.self::PENALTY_RULE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Penalty rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(PenaltyRule $rule): JsonResponse
    {
        return ApiResponse::success(new PenaltyRuleResource($rule), 'Penalty rule retrieved successfully');
    }

    /**
     * Update penalty rule
     *
     * Updates a penalty rule. Updating the amount range or reactivating
     * the rule re-runs the overlap check against other active rules.
     */
    #[Response(200, description: 'Penalty rule updated.', type: 'array{success: true, message: string, data: '.self::PENALTY_RULE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Penalty rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (invalid range, or the amount range overlaps an existing active rule).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['minimum_amount' => ['This amount range overlaps with an existing active penalty rule.']],
    ]])]
    public function update(UpdatePenaltyRuleRequest $request, PenaltyRule $rule): JsonResponse
    {
        $penaltyRule = $this->penaltyRuleService->update($rule, $request->validated());

        return ApiResponse::success(new PenaltyRuleResource($penaltyRule), 'Penalty rule updated successfully');
    }

    /**
     * Delete penalty rule
     *
     * Permanently deletes a penalty rule.
     */
    #[Response(200, description: 'Penalty rule deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Penalty rule deleted successfully',
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
    #[Response(404, description: 'Penalty rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function destroy(PenaltyRule $rule): JsonResponse
    {
        $this->penaltyRuleService->delete($rule);

        return ApiResponse::success(null, 'Penalty rule deleted successfully');
    }
}
