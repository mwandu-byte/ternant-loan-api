<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\OverdueLoansReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Overdue repayment schedule installments, determined exclusively via
 * OverdueService — the codebase's single, grace-period-aware source of
 * truth for "overdue". No overdue math is reimplemented here.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class OverdueLoansReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{overdue_loans_count: int, overdue_installments_count: int, total_overdue_amount: string, total_accrued_penalties: string}';

    private const ITEM_SCHEMA = 'array{loan_reference: string|null, customer: array{id: int, full_name: string, phone: string}|null, installment_number: int, due_date: string, expected_amount: string, paid_amount: string, outstanding_amount: string, days_overdue: int, penalty_amount: string|null, status: string}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly OverdueLoansReportService $reportService,
    ) {
        //
    }

    /**
     * Overdue loans report
     *
     * Returns overdue totals alongside a paginated, filterable list of
     * overdue installments. `date_from`/`date_to` filter on `due_date`.
     */
    #[QueryParameter('date_from', description: 'Only installments due on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only installments due on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('status', description: 'Filter by installment status.', type: 'string', example: 'pending')]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Overdue loans report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'status', 'customer_id', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Overdue loans report retrieved successfully');
    }
}
