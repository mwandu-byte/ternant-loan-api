<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Payment\StoreLoanPaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Services\Loan\LoanService;
use App\Services\Payment\PaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record and view loan disbursements.
 *
 * A Payment represents money the lending business has paid OUT — in
 * this module, exclusively a loan disbursement. It is completely
 * separate from customer repayments: creating a Payment never
 * creates a Repayment or a Receipt, and never modifies any repayment
 * schedule's balance.
 *
 * Only active loans are eligible for disbursement, and only one
 * disbursement is allowed per loan — partial disbursement is not
 * supported in this implementation, so the disbursed amount must
 * equal the loan's `principal_amount` exactly. A second disbursement
 * attempt against an already-disbursed loan is rejected, including
 * under concurrent requests (enforced by a database-level unique
 * constraint on `loan_id`, not just an application check).
 *
 * Normal application flow never needs the `store` endpoint below
 * directly: the Loans API already disburses a loan automatically the
 * moment it becomes `active` (see the Loans group), in the same
 * transaction as its repayment schedule generation. `store` exists
 * only as a manual recovery mechanism — e.g. a loan that reached
 * `active` before this automatic behavior existed — and still
 * enforces the same eligibility and duplicate-prevention rules.
 *
 * Payments are immutable once created — there are no update or
 * delete endpoints for them.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `payments.*` permission.
 */
#[Group('Payments')]
class PaymentController extends Controller
{
    private const PAYMENT_SCHEMA = 'array{id: int, loan_id: int, amount: string, payment_date: string, payment_method: string, reference_no: string|null, notes: string|null, paid_by: int, created_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly LoanService $loanService,
    ) {
        //
    }

    /**
     * List loan disbursements
     *
     * Returns a paginated, filterable list of loan disbursements
     * across all loans.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('loan_id', description: 'Filter by loan.', type: 'integer', example: 15)]
    #[QueryParameter('payment_method', description: 'Filter by payment method.', type: 'string', example: 'bank_transfer')]
    #[QueryParameter('payment_date_from', description: 'Only disbursements made on or after this date.', type: 'string', example: '2026-09-01')]
    #[QueryParameter('payment_date_to', description: 'Only disbursements made on or before this date.', type: 'string', example: '2026-12-31')]
    #[Response(200, description: 'Payments retrieved.', type: 'array{success: true, message: string, data: array{payments: '.self::PAYMENT_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the payments.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'payment_method' => ['nullable', 'string'],
            'payment_date_from' => ['nullable', 'date'],
            'payment_date_to' => ['nullable', 'date'],
        ]);

        $paginator = $this->paymentService->list($request->only([
            'loan_id', 'payment_method', 'payment_date_from', 'payment_date_to', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'payments' => PaymentResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Payments retrieved successfully');
    }

    /**
     * Disburse a loan
     *
     * Records the disbursement of the loan identified by the route
     * parameter. The loan must be `active`, must not already have a
     * disbursement, and the `amount` must equal the loan's
     * `principal_amount` exactly. Never creates a Repayment or
     * Receipt, and never modifies any repayment schedule.
     */
    #[Response(201, description: 'Loan disbursed.', type: 'array{success: true, message: string, data: '.self::PAYMENT_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the payments.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'This loan has already been disbursed.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'This loan has already been disbursed.',
    ]])]
    #[Response(422, description: 'Validation failed, the loan is not active, or the amount does not equal the loan\'s principal amount.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => "Disbursement amount must equal the loan's principal amount.",
    ]])]
    public function store(int $loan, StoreLoanPaymentRequest $request): JsonResponse
    {
        $loanModel = $this->loanService->find($loan);
        $payment = $this->paymentService->disburse($loanModel, $request->validated());

        return ApiResponse::success(
            new PaymentResource($payment),
            'Loan disbursed successfully',
            201,
        );
    }

    /**
     * Show a loan disbursement
     *
     * Returns a single loan disbursement payment.
     */
    #[Response(200, description: 'Payment retrieved.', type: 'array{success: true, message: string, data: '.self::PAYMENT_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the payments.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Payment does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Payment not found.',
    ]])]
    public function show(int $payment): JsonResponse
    {
        $model = $this->paymentService->find($payment);

        return ApiResponse::success(new PaymentResource($model), 'Payment retrieved successfully');
    }
}
