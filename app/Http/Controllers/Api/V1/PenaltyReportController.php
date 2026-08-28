<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\PenaltyReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penalties charged against overdue installments, sourced entirely from
 * the `penalties` table — penalty accrual itself belongs to
 * PenaltyService/PenaltyRuleService, never recalculated here.
 *
 * KNOWN DATA LIMITATION: nothing in the schema ever marks a penalty as
 * paid (Repayment has no penalty_id column, and Penalty.status is only
 * ever 'applied'). This report therefore exposes only `penalty_accrued`
 * and the real `penalty_status` column — it never reports a fabricated
 * "paid"/"outstanding" split.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class PenaltyReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_penalties_accrued: string, number_of_penalized_loans: int}';

    private const ITEM_SCHEMA = 'array{loan_reference: string|null, customer: array{id: int, full_name: string, phone: string}|null, installment_number: int|null, due_date: string|null, overdue_days: int|null, penalty_accrued: string, penalty_status: string, accrual_date: string}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly PenaltyReportService $reportService,
    ) {
        //
    }

    /**
     * Penalty report
     *
     * Returns penalty totals alongside a paginated, filterable list of
     * penalty charges. `date_from`/`date_to` filter on `applied_date`. No
     * "paid"/"outstanding" split is reported — see the class docblock.
     */
    #[QueryParameter('date_from', description: 'Only penalties applied on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only penalties applied on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('status', description: 'Filter by penalty status.', type: 'string', example: 'applied')]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
    #[QueryParameter('loan_id', description: 'Filter by loan.', type: 'integer', example: 15)]
    #[QueryParameter('repayment_schedule_id', description: 'Filter by repayment schedule installment.', type: 'integer', example: 4)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Penalty report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the reports.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'loan_id' => ['nullable', 'integer', 'exists:loans,id'],
            'repayment_schedule_id' => ['nullable', 'integer', 'exists:repayment_schedules,id'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'status', 'customer_id', 'loan_id', 'repayment_schedule_id', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Penalty report retrieved successfully');
    }
}
