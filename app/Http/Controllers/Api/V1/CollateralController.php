<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Collateral\StoreCollateralRequest;
use App\Http\Requests\Api\V1\Collateral\UpdateCollateralRequest;
use App\Http\Resources\Api\V1\CollateralResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\Collateral\CollateralService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage collateral records pledged against a customer.
 *
 * Collateral is a nested resource under Customer: every endpoint operates
 * within the scope of a single `{customer}`. Requesting a collateral ID
 * that belongs to a different customer behaves identically to requesting
 * an ID that does not exist at all — both return 404 "Collateral not found."
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `collateral.*` permission.
 */
#[Group('Collaterals')]
class CollateralController extends Controller
{
    private const COLLATERAL_SCHEMA = 'array{id: int, customer_id: int, type: string, description: string, estimated_value: string, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly CollateralService $collateralService,
    ) {
        //
    }

    /**
     * List a customer's collateral
     *
     * Returns a paginated, searchable, filterable list of collateral
     * belonging to the given customer.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('search', description: 'Matches against type and description.', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status.', type: 'string', example: 'active')]
    #[Response(200, description: 'Collateral retrieved.', type: 'array{success: true, message: string, data: array{collaterals: '.self::COLLATERAL_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the collateral.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function index(Customer $customer, Request $request): JsonResponse
    {
        $paginator = $this->collateralService->list($customer, $request->only([
            'search', 'status', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'collaterals' => CollateralResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Collateral retrieved successfully');
    }

    /**
     * Add collateral
     *
     * Registers a new collateral record against the given customer.
     */
    #[Response(201, description: 'Collateral created.', type: 'array{success: true, message: string, data: '.self::COLLATERAL_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the collateral.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['type' => ['The type field is required.']],
    ]])]
    public function store(Customer $customer, StoreCollateralRequest $request): JsonResponse
    {
        $collateral = $this->collateralService->create($customer, $request->validated());

        return ApiResponse::success(
            new CollateralResource($collateral),
            'Collateral created successfully',
            201,
        );
    }

    /**
     * Show collateral
     *
     * Returns a single collateral record belonging to the given customer.
     */
    #[Response(200, description: 'Collateral retrieved.', type: 'array{success: true, message: string, data: '.self::COLLATERAL_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the collateral.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, collateral does not exist, or collateral belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Collateral not found.',
    ]])]
    public function show(Customer $customer, int $collateral): JsonResponse
    {
        $collateral = $this->collateralService->findForCustomer($customer, $collateral);

        return ApiResponse::success(new CollateralResource($collateral), 'Collateral retrieved successfully');
    }

    /**
     * Update collateral
     *
     * Updates a collateral record belonging to the given customer. The
     * owning customer cannot be changed via this endpoint.
     */
    #[Response(200, description: 'Collateral updated.', type: 'array{success: true, message: string, data: '.self::COLLATERAL_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the collateral.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, collateral does not exist, or collateral belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Collateral not found.',
    ]])]
    #[Response(422, description: 'Validation failed.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['estimated_value' => ['The estimated value must be a number.']],
    ]])]
    public function update(Customer $customer, UpdateCollateralRequest $request, int $collateral): JsonResponse
    {
        $model = $this->collateralService->findForCustomer($customer, $collateral);
        $model = $this->collateralService->update($model, $request->validated());

        return ApiResponse::success(new CollateralResource($model), 'Collateral updated successfully');
    }

    /**
     * Delete collateral
     *
     * Permanently deletes a collateral record. Does not cascade to or
     * affect loans, repayments, payments, penalties, or documents.
     */
    #[Response(200, description: 'Collateral deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Collateral deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the collateral.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, collateral does not exist, or collateral belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Collateral not found.',
    ]])]
    public function destroy(Customer $customer, int $collateral): JsonResponse
    {
        $model = $this->collateralService->findForCustomer($customer, $collateral);
        $this->collateralService->delete($model);

        return ApiResponse::success(null, 'Collateral deleted successfully');
    }
}
