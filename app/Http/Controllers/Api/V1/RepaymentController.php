<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Repayment\StoreRepaymentRequest;
use App\Http\Resources\Api\V1\RepaymentResource;
use App\Http\Responses\ApiResponse;
use App\Services\Repayment\RepaymentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record and view actual customer repayments.
 *
 * A repayment is money the business has actually received from a
 * customer and applied against one installment of a loan's repayment
 * schedule — it is a real financial transaction, not the expected
 * repayment plan itself (see the Repayment Schedules group for that).
 *
 * Creating a repayment always creates one Receipt (the money actually
 * received — receipt number, amount, payment method, date) atomically
 * with one or more Repayment records, in a single database
 * transaction. If any part fails, everything is rolled back — there
 * is never a receipt without at least one repayment, or vice versa.
 *
 * If `amount` exceeds the targeted installment's outstanding balance,
 * the excess is not rejected — it rolls forward and is applied to the
 * loan's subsequent unpaid installments in order, one Repayment
 * record per installment it touches (all sharing the one Receipt).
 * Each affected installment's `outstanding_amount`/`status` is
 * recalculated in the same transaction. The request is only rejected
 * if `amount` exceeds the targeted installment's balance plus every
 * later unpaid installment's balance combined, or if the targeted
 * installment itself is already fully paid (a payment never
 * auto-skips forward from an already-settled target).
 *
 * If this repayment causes every installment on the loan to become
 * fully paid, the loan's status is automatically transitioned from
 * `active` to `completed` in the same transaction.
 *
 * Repayments are immutable once created — there are no update or
 * delete endpoints for them.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `repayments.*` permission.
 */
#[Group('Repayments')]
class RepaymentController extends Controller
{
    private const RECEIPT_SCHEMA = 'array{id: int, receipt_no: string, amount: string, receipt_date: string, payment_method: string, reference_no: string|null, received_by: int, notes: string|null, created_at: string}';

    private const REPAYMENT_SCHEMA = 'array{id: int, loan_id: int, repayment_schedule_id: int, receipt_id: int, receipt: '.self::RECEIPT_SCHEMA.', amount: string, repayment_date: string, notes: string|null, received_by: int, created_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly RepaymentService $repaymentService,
    ) {
        //
    }

    /**
     * List repayments
     *
     * Returns a paginated, filterable list of actual customer
     * repayments across all loans.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('loan_id', description: 'Filter by loan.', type: 'integer', example: 15)]
    #[QueryParameter('repayment_schedule_id', description: 'Filter by repayment schedule installment.', type: 'integer', example: 4)]
    #[QueryParameter('repayment_date_from', description: 'Only repayments made on or after this date.', type: 'string', example: '2026-09-01')]
    #[QueryParameter('repayment_date_to', description: 'Only repayments made on or before this date.', type: 'string', example: '2026-12-31')]
    #[QueryParameter('search', description: 'Matches against the linked receipt\'s receipt_no or reference_no.', type: 'string')]
    #[Response(200, description: 'Repayments retrieved.', type: 'array{success: true, message: string, data: array{repayments: '.self::REPAYMENT_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayments.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'repayment_schedule_id' => ['nullable', 'integer', 'exists:repayment_schedules,id'],
            'repayment_date_from' => ['nullable', 'date'],
            'repayment_date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string'],
        ]);

        $paginator = $this->repaymentService->list($request->only([
            'loan_id', 'repayment_schedule_id', 'repayment_date_from', 'repayment_date_to', 'search', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'repayments' => RepaymentResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Repayments retrieved successfully');
    }

    /**
     * Record a repayment
     *
     * Records that a customer has actually paid `amount` starting
     * against the installment identified by `repayment_schedule_id`.
     * `repayment_schedule_id` must belong to `loan_id` — this is
     * verified explicitly, never assumed. Creates one Receipt
     * atomically with one or more Repayment records — more than one
     * only when `amount` exceeds the targeted installment's
     * outstanding balance and rolls forward into later unpaid
     * installments — recalculating every affected installment's
     * outstanding balance and status in the same transaction. If this
     * payment fully settles the loan's last unpaid installment, the
     * loan is automatically transitioned to `completed`. Returns every
     * Repayment record created by this request, in installment order.
     */
    #[Response(201, description: 'Repayment recorded.', type: 'array{success: true, message: string, data: array{repayments: '.self::REPAYMENT_SCHEMA.'[]}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayments.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Loan or repayment schedule does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Repayment schedule not found.',
    ]])]
    #[Response(409, description: 'A receipt already exists with the supplied reference_no.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'A receipt already exists with this reference number.',
    ]])]
    #[Response(422, description: 'Validation failed, the repayment schedule does not belong to the supplied loan, the targeted installment is already fully paid, or the amount exceeds the total remaining outstanding balance on the loan.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['amount' => ['The amount field must be greater than 0.']],
    ]])]
    public function store(StoreRepaymentRequest $request): JsonResponse
    {
        $repayments = $this->repaymentService->create($request->validated());

        return ApiResponse::success(
            ['repayments' => RepaymentResource::collection($repayments)->resolve()],
            'Repayment recorded successfully',
            201,
        );
    }

    /**
     * Show a repayment
     *
     * Returns a single repayment, including its linked receipt, loan,
     * and repayment schedule installment.
     */
    #[Response(200, description: 'Repayment retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayments.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Repayment not found.',
    ]])]
    public function show(int $repayment): JsonResponse
    {
        $model = $this->repaymentService->find($repayment);
        $this->authorize('view', $model);

        return ApiResponse::success(new RepaymentResource($model), 'Repayment retrieved successfully');
    }
}
