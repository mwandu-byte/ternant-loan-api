<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\RepaymentScheduleResource;
use App\Http\Responses\ApiResponse;
use App\Services\Loan\LoanService;
use App\Services\Repayment\RepaymentScheduleService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * View and generate loan repayment schedules.
 *
 * A repayment schedule is a plan of what a customer is expected to pay
 * over the life of a loan — it is not a record of what has actually
 * been paid. Each installment is generated from the loan's own frozen,
 * already-applied financial terms (`principal_amount`, `interest_amount`,
 * `total_amount`, `repayment_frequency`, `repayment_term`, `start_date`)
 * and never re-reads the current lending configuration, so a loan's
 * schedule stays consistent even if interest rules or repayment
 * frequencies are changed later.
 *
 * A loan's schedule is generated automatically by the Loan module
 * itself — whenever a loan is created as `active`, or whenever an
 * update transitions its status from a non-active value to `active` —
 * so normal application flow never calls the `generate` endpoint
 * below directly; see the Loans group. `generate` exists only as a
 * manual recovery mechanism (e.g. a loan that reached `active` before
 * this automatic behavior existed) and still enforces the same rules:
 * the loan must be `active`, and a schedule may only be generated
 * once per loan. `outstanding_amount` is set equal to `total_amount`
 * on generation and is not maintained by this module — recording
 * actual payments and updating `outstanding_amount` belongs to the
 * separate Payment Management module, not implemented here. The
 * `status` returned for each installment (`pending`, `due`,
 * `overdue`) is derived from `due_date` relative to today AND the
 * configured grace period — an installment past its due date but
 * still within grace shows `due`, not `overdue`; see the Loan
 * Configuration group's grace period endpoint. A `paid` or
 * `partially_paid` status, once set by Payment Management, is
 * returned unchanged. Each installment also reports `is_overdue`,
 * `days_overdue`, `grace_period_expires_at`, and `penalties_accrued`
 * (the sum of any penalties charged against it — see the Penalties
 * group).
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * relevant `repayment-schedules.*` permission.
 */
#[Group('Repayment Schedules')]
class RepaymentScheduleController extends Controller
{
    private const REPAYMENT_SCHEMA = 'array{id: int, loan_id: int, installment_number: int, due_date: string, principal_amount: string, interest_amount: string, total_amount: string, outstanding_amount: string, status: string, is_overdue: bool, days_overdue: int, grace_period_expires_at: string|null, penalties_accrued: string, created_at: string, updated_at: string}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const NOT_FOUND_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly RepaymentScheduleService $repaymentScheduleService,
        private readonly LoanService $loanService,
    ) {
        //
    }

    /**
     * List repayment schedules
     *
     * Returns a paginated, filterable list of repayment schedule
     * installments across all loans. The `status` filter matches the
     * stored status only (`pending`, or `paid`/`partially_paid` once
     * set by Payment Management) — `due` and `overdue` are computed
     * display values derived from `due_date` and cannot be filtered on
     * directly.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('loan_id', description: 'Filter by loan.', type: 'integer', example: 15)]
    #[QueryParameter('status', description: 'Filter by the stored status.', type: 'string', example: 'pending')]
    #[QueryParameter('due_date_from', description: 'Only installments due on or after this date.', type: 'string', example: '2026-09-01')]
    #[QueryParameter('due_date_to', description: 'Only installments due on or before this date.', type: 'string', example: '2026-12-31')]
    #[Response(200, description: 'Repayment schedules retrieved.', type: 'array{success: true, message: string, data: array{repayment_schedules: '.self::REPAYMENT_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayment-schedules.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'The loan_id filter does not reference an existing loan.', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['loan_id' => ['The selected loan id is invalid.']],
    ]])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'status' => ['nullable', 'string'],
            'due_date_from' => ['nullable', 'date'],
            'due_date_to' => ['nullable', 'date'],
        ]);

        $paginator = $this->repaymentScheduleService->list($request->only([
            'loan_id', 'status', 'due_date_from', 'due_date_to', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'repayment_schedules' => RepaymentScheduleResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Repayment schedules retrieved successfully');
    }

    /**
     * Show a repayment schedule installment
     *
     * Returns a single repayment schedule installment.
     */
    #[Response(200, description: 'Repayment schedule installment retrieved.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayment-schedules.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Repayment schedule installment does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Repayment schedule not found.',
    ]])]
    public function show(int $repayment): JsonResponse
    {
        $model = $this->repaymentScheduleService->find($repayment);

        return ApiResponse::success(new RepaymentScheduleResource($model), 'Repayment schedule retrieved successfully');
    }

    /**
     * List a loan's repayment schedule
     *
     * Returns the full repayment schedule belonging to the given loan.
     */
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[QueryParameter('status', description: 'Filter by the stored status.', type: 'string', example: 'pending')]
    #[QueryParameter('due_date_from', description: 'Only installments due on or after this date.', type: 'string', example: '2026-09-01')]
    #[QueryParameter('due_date_to', description: 'Only installments due on or before this date.', type: 'string', example: '2026-12-31')]
    #[Response(200, description: 'Repayment schedule retrieved.', type: 'array{success: true, message: string, data: array{repayment_schedules: '.self::REPAYMENT_SCHEMA.'[], pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayment-schedules.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    public function indexForLoan(int $loan, Request $request): JsonResponse
    {
        $loanModel = $this->loanService->find($loan);

        $paginator = $this->repaymentScheduleService->listForLoan($loanModel, $request->only([
            'status', 'due_date_from', 'due_date_to', 'per_page', 'page',
        ]));

        return ApiResponse::success([
            'repayment_schedules' => RepaymentScheduleResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Repayment schedule retrieved successfully');
    }

    /**
     * Generate a loan's repayment schedule (manual recovery)
     *
     * Normal application flow never needs this endpoint: the Loans API
     * already generates a schedule automatically the moment a loan
     * becomes `active`. This endpoint exists only to recover a loan
     * that reached `active` without one. Generates the schedule for
     * the given loan from its own frozen financial terms and
     * configured repayment frequency/term. Only allowed once, and
     * only while the loan is `active`. The generated installments'
     * principal, interest, and total amounts always sum exactly to
     * the loan's `principal_amount`, `interest_amount`, and
     * `total_amount` respectively.
     */
    #[Response(201, description: 'Repayment schedule generated.', type: 'array{success: true, message: string, data: '.self::REPAYMENT_SCHEMA.'[]}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the repayment-schedules.create permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(404, description: 'Loan does not exist.', type: self::NOT_FOUND_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Loan not found.',
    ]])]
    #[Response(409, description: 'A repayment schedule has already been generated for this loan.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'A repayment schedule has already been generated for this loan.',
    ]])]
    #[Response(422, description: 'The loan is not active and is not eligible for repayment schedule generation.', type: 'array{success: false, message: string}', examples: [[
        'success' => false,
        'message' => 'Only active loans are eligible for repayment schedule generation.',
    ]])]
    public function generate(int $loan): JsonResponse
    {
        $loanModel = $this->loanService->find($loan);
        $schedule = $this->repaymentScheduleService->generateForLoan($loanModel);

        return ApiResponse::success(
            RepaymentScheduleResource::collection($schedule)->resolve(),
            'Repayment schedule generated successfully',
            201,
        );
    }
}
