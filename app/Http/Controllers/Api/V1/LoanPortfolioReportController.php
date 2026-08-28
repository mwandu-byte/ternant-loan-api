<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\LoanPortfolioReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loan portfolio overview: status/amount summary plus a paginated list of
 * loans. Paid/outstanding amounts are derived from each loan's
 * repayment_schedules, never recalculated independently.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class LoanPortfolioReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_loans: int, active_loans: int, pending_loans: int, completed_loans: int, cancelled_loans: int, overdue_loans: int, total_principal: string, total_interest: string, total_loan_amount: string, total_paid: string, total_outstanding: string, total_penalties: string}';

    private const ITEM_SCHEMA = 'array{reference_no: string, customer: array{id: int, full_name: string, phone: string}|null, principal_amount: string, interest_rate: string, applied_interest_rate: string, interest_amount: string, total_amount: string, paid_amount: string, outstanding_amount: string, repayment_frequency: string, repayment_term: int, start_date: string, due_date: string, status: string}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly LoanPortfolioReportService $reportService,
    ) {
        //
    }

    /**
     * Loan portfolio report
     *
     * Returns a portfolio-wide summary alongside a paginated, filterable
     * list of loans. `date_from`/`date_to` filter on each loan's own
     * `start_date`.
     */
    #[QueryParameter('date_from', description: 'Only loans with start_date on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only loans with start_date on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('status', description: 'Filter by loan status.', type: 'string', example: 'active')]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Loan portfolio report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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

        return ApiResponse::success($report, 'Loan portfolio report retrieved successfully');
    }
}
