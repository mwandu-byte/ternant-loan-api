<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Loan\StoreLoanRequest;
use App\Http\Requests\Api\V1\Loan\UpdateLoanRequest;
use App\Http\Resources\Api\V1\LoanResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\Loan\LoanService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage loans issued to a customer.
 *
 * Loans are a nested resource under Customer: every endpoint operates
 * within the scope of a single `{customer}`. Requesting a loan ID that
 * belongs to a different customer behaves identically to requesting an
 * ID that does not exist at all — both return 404 "Loan not found."
 *
 * The interest rate, interest amount, total amount, reference number,
 * and due date are calculated automatically from the configured
 * lending rules and cannot be supplied by the client. A loan may
 * optionally be linked to existing collateral records already
 * belonging to the same customer — no collateral is created here.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `loans.*` permission.
 */
#[Group('Loans')]
class LoanController extends Controller
{
    private const LOAN_SCHEMA = 'array{id: int, customer_id: int, reference_no: string, principal_amount: string, interest_rate: string, interest_amount: string, total_amount: string, repayment_frequency: string, repayment_term: int, start_date: string, due_date: string, status: string, notes: string|null, collaterals: array, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly LoanService $loanService,
    ) {
        //
    }

    /**
     * List a customer's loans
     *
     * Returns a paginated, searchable, filterable list of loans
     * belonging to the given customer.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('search', description: 'Matches against reference_no.', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status.', type: 'string', example: 'active')]
    #[Response(200, description: 'Loans retrieved.', type: 'array{success: true, message: string, data: array{loans: '.self::LOAN_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loans.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    public function index(Customer $customer, Request $request): JsonResponse
    {
        $paginator = $this->loanService->list($customer, $request->only([
            'search', 'status', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'loans' => LoanResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Loans retrieved successfully');
    }

    /**
     * Create a loan
     *
     * Issues a new loan against the given customer. The interest rate,
     * interest amount, total amount, reference number, and due date are
     * calculated automatically and cannot be supplied directly.
     */
    #[Response(201, description: 'Loan created.', type: 'array{success: true, message: string, data: '.self::LOAN_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loans.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The requested resource was not found.',
    ]])]
    #[Response(422, description: 'Validation failed, principal amount is outside the configured lending range, or selected collateral does not belong to this customer.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['repayment_term' => ['The repayment term field is required.']],
    ]])]
    public function store(Customer $customer, StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->loanService->create($customer, $request->validated());

        return ApiResponse::success(
            new LoanResource($loan),
            'Loan created successfully',
            201,
        );
    }

    /**
     * Show a loan
     *
     * Returns a single loan belonging to the given customer, including
     * its associated collateral records.
     */
    #[Response(200, description: 'Loan retrieved.', type: 'array{success: true, message: string, data: '.self::LOAN_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loans.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, loan does not exist, or loan belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    public function show(Customer $customer, int $loan): JsonResponse
    {
        $loan = $this->loanService->findForCustomer($customer, $loan);

        return ApiResponse::success(new LoanResource($loan), 'Loan retrieved successfully');
    }

    /**
     * Update a loan
     *
     * Updates a loan belonging to the given customer. Editable fields
     * depend on the loan's current status: pending loans allow full
     * editing of the repayment schedule and collateral selection;
     * active loans allow only `notes` and a status transition to
     * `completed` or `cancelled`; completed/cancelled loans cannot be
     * modified at all. The financial fields calculated at creation
     * (`reference_no`, `principal_amount`, `interest_rate`,
     * `interest_amount`, `total_amount`) can never be changed.
     */
    #[Response(200, description: 'Loan updated.', type: 'array{success: true, message: string, data: '.self::LOAN_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loans.update permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, loan does not exist, or loan belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'Loan is completed or cancelled and can no longer be modified.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This loan can no longer be modified.',
    ]])]
    #[Response(422, description: 'Validation failed, an immutable field was supplied for an active loan, an invalid status transition was requested, or selected collateral does not belong to this customer.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['status' => ["Cannot transition loan status from 'pending' to 'completed'."]],
    ]])]
    public function update(Customer $customer, UpdateLoanRequest $request, int $loan): JsonResponse
    {
        $model = $this->loanService->findForCustomer($customer, $loan);
        $model = $this->loanService->update($model, $request->validated());

        return ApiResponse::success(new LoanResource($model), 'Loan updated successfully');
    }

    /**
     * Delete a loan
     *
     * Pending loans are permanently deleted. Active loans are
     * cancelled instead — the record is kept with status `cancelled`.
     * Completed or already-cancelled loans cannot be deleted or
     * cancelled. Does not cascade into repayment, payment, or penalty
     * records.
     */
    #[Response(200, description: 'Loan deleted or cancelled.', type: 'array{success: true, message: string, data: null}', examples: [[
        'success' => true,
        'message' => 'Loan deleted successfully',
        'data' => null,
    ]])]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the loans.delete permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Customer does not exist, loan does not exist, or loan belongs to a different customer.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'Loan is completed or already cancelled.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This loan can no longer be modified.',
    ]])]
    public function destroy(Customer $customer, int $loan): JsonResponse
    {
        $model = $this->loanService->findForCustomer($customer, $loan);
        $message = $this->loanService->delete($model);

        return ApiResponse::success(null, $message);
    }
}
