<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\InterestRule\StoreInterestRuleRequest;
use App\Http\Requests\Api\V1\InterestRule\UpdateInterestRuleRequest;
use App\Http\Resources\Api\V1\InterestRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\InterestRule;
use App\Services\LoanConfiguration\InterestRuleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * Manage interest rate rules.
 *
 * Interest rules are the business's default lending rate brackets applied by
 * principal amount. Multiple active rules may exist but their amount
 * ranges must never overlap, so a given loan amount always resolves to
 * exactly one rate. A specific loan may separately override the resolved
 * rate via its own discount fields without ever modifying these rules.
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
class InterestRuleController extends Controller
{
    private const INTEREST_RULE_SCHEMA = 'array{id: int, minimum_amount: string, maximum_amount: string|null, interest_rate: string, calculation_method: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly InterestRuleService $interestRuleService,
    ) {
        //
    }

    /**
     * List interest rules
     *
     * Returns every configured interest rule, active and inactive.
     */
    #[Response(200, description: 'Interest rules retrieved.', type: 'array{success: true, message: string, data: '.self::INTEREST_RULE_SCHEMA.'[]}')]
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
            InterestRuleResource::collection($this->interestRuleService->list())->resolve(),
            'Interest rules retrieved successfully',
        );
    }

    /**
     * Create interest rule
     *
     * Creates a new interest rule. The amount range of an active rule
     * must not overlap the range of any other active rule.
     */
    #[Response(201, description: 'Interest rule created.', type: 'array{success: true, message: string, data: '.self::INTEREST_RULE_SCHEMA.'}')]
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
        'errors' => ['minimum_amount' => ['This amount range overlaps with an existing active interest rule.']],
    ]])]
    public function store(StoreInterestRuleRequest $request): JsonResponse
    {
        $interestRule = $this->interestRuleService->create($request->validated());

        return ApiResponse::success(new InterestRuleResource($interestRule), 'Interest rule created successfully', 201);
    }

    /**
     * Show interest rule
     *
     * Returns a single interest rule.
     */
    #[Response(200, description: 'Interest rule retrieved.', type: 'array{success: true, message: string, data: '.self::INTEREST_RULE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Interest rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(InterestRule $rule): JsonResponse
    {
        return ApiResponse::success(new InterestRuleResource($rule), 'Interest rule retrieved successfully');
    }

    /**
     * Update interest rule
     *
     * Updates an interest rule. Updating the amount range or reactivating
     * the rule re-runs the overlap check against other active rules.
     */
    #[Response(200, description: 'Interest rule updated.', type: 'array{success: true, message: string, data: '.self::INTEREST_RULE_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loan-configurations.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Interest rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (invalid range, or the amount range overlaps an existing active rule).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['minimum_amount' => ['This amount range overlaps with an existing active interest rule.']],
    ]])]
    public function update(UpdateInterestRuleRequest $request, InterestRule $rule): JsonResponse
    {
        $interestRule = $this->interestRuleService->update($rule, $request->validated());

        return ApiResponse::success(new InterestRuleResource($interestRule), 'Interest rule updated successfully');
    }

    /**
     * Delete interest rule
     *
     * Permanently deletes an interest rule. Loans already created against
     * this rule are unaffected — they retain their own snapshotted rate.
     */
    #[Response(200, description: 'Interest rule deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Interest rule deleted successfully',
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
    #[Response(404, description: 'Interest rule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function destroy(InterestRule $rule): JsonResponse
    {
        $this->interestRuleService->delete($rule);

        return ApiResponse::success(null, 'Interest rule deleted successfully');
    }
}
