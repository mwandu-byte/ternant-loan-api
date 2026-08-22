<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Customer\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\Customer\CustomerService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage customer profile records.
 *
 * Customers are records managed by authenticated staff/admin/lender users
 * via the mobile application. Customers never authenticate and have no
 * roles or permissions of their own.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `customers.*` permission.
 */
#[Group('Customers')]
class CustomerController extends Controller
{
    private const CUSTOMER_SCHEMA = 'array{id: int, full_name: string, phone: string, email: string|null, identification_type: string, identification_number: string, gender: string|null, address: string, photo_url: string|null, status: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly CustomerService $customerService,
    ) {
        //
    }

    /**
     * List customers
     *
     * Returns a paginated, searchable, filterable list of customers.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('search', description: 'Matches against full_name, phone, and identification_number.', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status.', type: 'string', example: 'active')]
    #[QueryParameter('sort_by', description: 'One of: full_name, created_at.', type: 'string', default: 'created_at')]
    #[QueryParameter('sort_dir', description: 'One of: asc, desc.', type: 'string', default: 'desc')]
    #[Response(200, description: 'Customers retrieved.', type: 'array{success: true, message: string, data: array{customers: '.self::CUSTOMER_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the customers.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->customerService->list($request->only([
            'search', 'status', 'sort_by', 'sort_dir', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'customers' => CustomerResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Customers retrieved successfully');
    }

    /**
     * Create customer
     *
     * Creates a new customer profile. Accepts multipart/form-data so a
     * photograph may optionally be uploaded alongside the profile data.
     */
    #[Response(201, description: 'Customer created.', type: 'array{success: true, message: string, data: '.self::CUSTOMER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the customers.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (missing required field, duplicate phone, or duplicate identification number for the given identification type).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['phone' => ['This phone number is already registered.']],
    ]])]
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create(
            $request->safe()->except('photo'),
            $request->file('photo'),
        );

        return ApiResponse::success(
            new CustomerResource($customer),
            'Customer created successfully',
            201,
        );
    }

    /**
     * Show customer
     *
     * Returns a single customer's profile information.
     */
    #[Response(200, description: 'Customer retrieved.', type: 'array{success: true, message: string, data: '.self::CUSTOMER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the customers.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function show(Customer $customer): JsonResponse
    {
        return ApiResponse::success(new CustomerResource($customer), 'Customer retrieved successfully');
    }

    /**
     * Update customer
     *
     * Updates a customer's profile information. If a new photo is
     * uploaded, it replaces the existing one and the previous file is
     * removed from storage.
     */
    #[Response(200, description: 'Customer updated.', type: 'array{success: true, message: string, data: '.self::CUSTOMER_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the customers.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed (duplicate phone, or duplicate identification number for the given identification type).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['phone' => ['This phone number is already registered.']],
    ]])]
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->update(
            $customer,
            $request->safe()->except('photo'),
            $request->file('photo'),
        );

        return ApiResponse::success(new CustomerResource($customer), 'Customer updated successfully');
    }

    /**
     * Delete customer
     *
     * Permanently deletes a customer profile and its stored photograph,
     * if any. Does not cascade to loans, collateral, repayments,
     * penalties, or documents.
     */
    #[Response(200, description: 'Customer deleted.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Customer deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the customers.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(409, description: 'Customer cannot be deleted because related records exist.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This customer cannot be deleted because related records exist.',
    ]])]
    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return ApiResponse::success(null, 'Customer deleted successfully');
    }
}
