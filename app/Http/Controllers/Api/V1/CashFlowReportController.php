<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\CashFlowReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money IN (loan repayments) vs money OUT (loan disbursements), built
 * entirely from the existing `payments` and `repayments`/`receipts`
 * tables via a database UNION ALL — never a new cash-flow table, never
 * materializing both tables into memory to merge/sort in PHP.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class CashFlowReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_money_in: string, total_money_out: string, net_cash_flow: string}';

    private const ITEM_SCHEMA = 'array{type: string, reference: string|null, amount: string, date: string, customer: array{id: int, full_name: string}|null, loan_reference: string|null, payment_method: string|null, description: string|null}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly CashFlowReportService $reportService,
    ) {
        //
    }

    /**
     * Cash flow report
     *
     * Returns money-in/money-out/net totals alongside a paginated,
     * date-sorted list of transactions combining disbursements (money
     * out) and repayments (money in). `date_from`/`date_to` filter each
     * side on its own business date (`payment_date` for disbursements,
     * `repayment_date` for collections).
     */
    #[QueryParameter('date_from', description: 'Only transactions on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only transactions on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('type', description: 'Restrict to one direction of cash flow.', type: 'string', example: 'collection')]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
    #[QueryParameter('user_id', description: 'Filter by the user who processed the transaction (disburser or collector).', type: 'integer', example: 3)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Cash flow report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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
            'type' => ['nullable', 'string', 'in:disbursement,collection'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'type', 'customer_id', 'user_id', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Cash flow report retrieved successfully');
    }
}
