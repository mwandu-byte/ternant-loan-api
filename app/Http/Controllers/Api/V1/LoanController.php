<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Loan\StoreLoanRequest;
use App\Http\Requests\Api\V1\Loan\UpdateLoanRequest;
use App\Http\Resources\Api\V1\LoanResource;
use App\Http\Responses\ApiResponse;
use App\Services\Loan\LoanService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage loans.
 *
 * Loans are a top-level resource. Each loan belongs to a customer via
 * its `customer_id`, but the API does not nest loan endpoints under a
 * customer route — `customer_id` is supplied in the request body when
 * creating a loan, and may be used as a query filter when listing.
 *
 * The interest rate, interest amount, total amount, reference number,
 * and due date are calculated automatically from the configured
 * lending rules (see the Loan Configuration group) and cannot be
 * supplied by the client. `interest_rate` is always the rate resolved
 * from the active Interest Rule matching the principal amount at
 * creation time; it is never changed retroactively by later
 * configuration changes. A loan may instead request a one-off
 * discounted rate via `has_discount`/`discount_rate` — this never
 * modifies the global Interest Rule, it only overrides the rate used
 * for this specific loan. The rate actually used for the interest
 * calculation is always exposed as `applied_interest_rate` (equal to
 * `interest_rate` when no discount was requested, or to
 * `discount_rate` when one was). A loan may optionally be linked to
 * existing collateral records already belonging to the same customer
 * — no collateral is created here. `customer_id` is immutable once a
 * loan is created and can never be changed via update.
 *
 * A loan's repayment schedule is generated automatically — never by a
 * separate client call — whenever a loan becomes `active`: either
 * immediately on creation (`status: "active"`), or later when an
 * update transitions its status from a non-active value to `active`.
 * Generation uses only the loan's own frozen `principal_amount`,
 * `interest_amount`, `total_amount`, `repayment_frequency`, and
 * `repayment_term` — never the current lending configuration — and
 * runs in the same database transaction as the create/update, so a
 * loan can never end up `active` without its schedule, or vice versa.
 * An already-active loan updated for unrelated fields (or re-saved
 * with the same status) never regenerates or duplicates its schedule.
 * Every loan response includes `repayment_schedules` (empty until the
 * loan is active).
 *
 * The same non-active-to-active transition also disburses the loan
 * automatically, in the same transaction: a Payment is created for
 * the full `principal_amount`, using the optional `payment_method`
 * (defaults to the first configured method) and
 * `payment_reference_no` supplied in this request — see the Payments
 * group. This means creating or activating a loan requires no
 * separate disbursement call under normal flow; `POST
 * /loans/{loan}/payments` remains available only as a manual recovery
 * path for a loan that reached `active` before this behavior existed
 * (it correctly rejects a loan that already has a disbursement).
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `loans.*` permission.
 */
#[Group('Loans')]
class LoanController extends Controller
{
    private const CUSTOMER_SCHEMA = 'array{id: int, full_name: string, phone: string, email: string|null, identification_type: string, identification_number: string, gender: string|null, address: string, photo_url: string|null, status: string, created_at: string, updated_at: string}';

    private const REPAYMENT_SCHEMA = 'array{id: int, loan_id: int, installment_number: int, due_date: string, principal_amount: string, interest_amount: string, total_amount: string, outstanding_amount: string, status: string, created_at: string, updated_at: string}';

    private const LOAN_SCHEMA = 'array{id: int, customer_id: int, customer: '.self::CUSTOMER_SCHEMA.', reference_no: string, principal_amount: string, interest_rate: string, interest_amount: string, total_amount: string, has_discount: bool, discount_rate: string|null, applied_interest_rate: string, repayment_frequency: string, repayment_term: int, start_date: string, due_date: string, status: string, notes: string|null, collaterals: array, repayment_schedules: '.self::REPAYMENT_SCHEMA.'[], created_at: string, updated_at: string}';

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
     * List loans
     *
     * Returns a paginated, searchable, filterable list of loans across
     * all customers. Pass `customer_id` to scope the results to a
     * single customer.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
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
    #[Response(422, description: 'The customer_id filter does not reference an existing customer.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['customer_id' => ['The selected customer id is invalid.']],
    ]])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $paginator = $this->loanService->list($request->only([
            'customer_id', 'search', 'status', 'per_page', 'page',
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
     * Issues a new loan for the customer identified by `customer_id`
     * in the request body. The interest rate, interest amount, total
     * amount, reference number, and due date are calculated
     * automatically and cannot be supplied directly. Pass
     * `has_discount: true` and a `discount_rate` to apply a one-off
     * discounted rate to this loan only; the global Interest Rule
     * configuration is never modified. If `status` is `active`, the
     * repayment schedule is generated automatically in the same
     * transaction as the loan itself — if generation fails, the loan
     * is not created. A loan created with any other status has no
     * schedule until it is later activated via update.
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
    #[Response(422, description: 'Validation failed, customer_id does not reference an existing customer, principal amount is outside the configured lending range, or selected collateral does not belong to the selected customer.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['customer_id' => ['The selected customer id is invalid.']],
    ]])]
    public function store(StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->loanService->create($request->validated());

        return ApiResponse::success(
            new LoanResource($loan),
            'Loan created successfully',
            201,
        );
    }

    /**
     * Show a loan
     *
     * Returns a single loan, including its owning customer and
     * associated collateral records.
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
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    public function show(int $loan): JsonResponse
    {
        $model = $this->loanService->find($loan);

        return ApiResponse::success(new LoanResource($model), 'Loan retrieved successfully');
    }

    /**
     * Update a loan
     *
     * Updates a loan. Editable fields depend on the loan's current
     * status: pending loans allow full editing of the repayment
     * schedule and collateral selection; active loans allow only
     * `notes` and a status transition to `completed` or `cancelled`;
     * completed/cancelled loans cannot be modified at all. The
     * financial fields calculated at creation (`customer_id`,
     * `reference_no`, `principal_amount`, `interest_rate`,
     * `interest_amount`, `total_amount`, `has_discount`,
     * `discount_rate`, `applied_interest_rate`) can never be changed —
     * a loan can never be transferred to a different customer. If this
     * update transitions `status` from a non-active value to `active`,
     * the repayment schedule is generated automatically in the same
     * transaction — if generation fails, the status change is rolled
     * back. Updating an already-active loan (or any other transition)
     * never regenerates or duplicates the schedule.
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
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'Loan is completed or cancelled and can no longer be modified.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This loan can no longer be modified.',
    ]])]
    #[Response(422, description: 'Validation failed, an immutable field was supplied for an active loan, an invalid status transition was requested, or selected collateral does not belong to this loan\'s customer.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['status' => ["Cannot transition loan status from 'pending' to 'completed'."]],
    ]])]
    public function update(UpdateLoanRequest $request, int $loan): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $wasActive = $model->status === 'active';

        $model = $this->loanService->update($model, $request->validated());

        $message = ! $wasActive && $model->status === 'active'
            ? 'Loan activated successfully'
            : 'Loan updated successfully';

        return ApiResponse::success(new LoanResource($model), $message);
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
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'Loan is completed or already cancelled.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This loan can no longer be modified.',
    ]])]
    public function destroy(int $loan): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $message = $this->loanService->delete($model);

        return ApiResponse::success(null, $message);
    }
}
