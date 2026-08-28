<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\CustomerReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer portfolio overview. Customer has no dedicated business date of
 * its own (unlike Loan's start_date), so `date_from`/`date_to`
 * intentionally scope on `created_at` (registration date) — the only real
 * date column available, not a blind default.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class CustomerReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_customers: int, active_customers: int, inactive_customers: int, customers_with_active_loans: int, customers_with_overdue_loans: int}';

    private const ITEM_SCHEMA = 'array{customer: array{id: int, full_name: string}, phone: string, status: string, number_of_loans: int, total_borrowed: string, total_repaid: string, outstanding_amount: string}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly CustomerReportService $reportService,
    ) {
        //
    }

    /**
     * Customer report
     *
     * Returns customer-portfolio totals alongside a paginated, filterable
     * list of customers with their loan/repayment aggregates.
     * `date_from`/`date_to` filter on `created_at`.
     */
    #[QueryParameter('date_from', description: 'Only customers registered on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only customers registered on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('status', description: 'Filter by customer status.', type: 'string', example: 'active')]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Customer report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'status', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Customer report retrieved successfully');
    }
}
