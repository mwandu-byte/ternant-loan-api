<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\DisbursementReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money OUT through loan disbursements, sourced entirely from the
 * `payments` table (a Payment is always a disbursement, never a
 * repayment). Payment has no status column, so no status field/filter is
 * offered.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class DisbursementReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_disbursed: string, number_of_disbursements: int, average_disbursement: string}';

    private const ITEM_SCHEMA = 'array{payment_reference: string|null, loan_reference: string|null, customer: array{id: int, full_name: string, phone: string}|null, amount: string, payment_method: string, payment_date: string, notes: string|null, disbursed_by: string|null}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly DisbursementReportService $reportService,
    ) {
        //
    }

    /**
     * Disbursement report
     *
     * Returns disbursement totals alongside a paginated, filterable list
     * of individual disbursements. `date_from`/`date_to` filter on
     * `payment_date`.
     */
    #[QueryParameter('date_from', description: 'Only disbursements on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only disbursements on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('customer_id', description: 'Filter by the disbursed loan\'s customer.', type: 'integer', example: 10)]
    #[QueryParameter('user_id', description: 'Filter by the user who disbursed the payment.', type: 'integer', example: 3)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Disbursement report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'customer_id', 'user_id', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Disbursement report retrieved successfully');
    }
}
