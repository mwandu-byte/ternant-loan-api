<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Dashboard\DashboardRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\DashboardService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

/**
 * High-level lending-business summary.
 *
 * A single endpoint aggregating customer, loan, financial, repayment,
 * and disbursement metrics via efficient count()/sum() queries — no
 * collection is ever loaded into PHP just to total it up. `from`/`to`
 * scope event-dated metrics only (loans created, amounts
 * issued/collected/interest/penalties, total repayments); point-in-time
 * balance metrics (outstanding, overdue, upcoming) and the "today"
 * metrics are never date-ranged. See DashboardService for the exact
 * scoping rule per metric.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `dashboard.view` permission.
 */
#[Group('Dashboard')]
class DashboardController extends Controller
{
    private const CUSTOMERS_SCHEMA = 'array{total: int, active: int}';

    private const LOANS_SCHEMA = 'array{total: int, active: int, pending: int, completed: int, overdue: int}';

    private const FINANCIAL_SCHEMA = 'array{total_amount_issued: string, total_amount_collected: string, total_outstanding_amount: string, total_interest_generated: string, total_penalties_generated: string}';

    private const REPAYMENTS_SCHEMA = 'array{total: int, today: int, overdue: int, upcoming: int}';

    private const PAYMENTS_SCHEMA = 'array{total_disbursements: int, today_disbursements: int}';

    private const SUMMARY_SCHEMA = 'array{customers: '.self::CUSTOMERS_SCHEMA.', loans: '.self::LOANS_SCHEMA.', financial: '.self::FINANCIAL_SCHEMA.', repayments: '.self::REPAYMENTS_SCHEMA.', payments: '.self::PAYMENTS_SCHEMA.'}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    private const VALIDATION_ERROR_SCHEMA = 'array{success: false, message: string, errors: array<string, string[]>}';

    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
        //
    }

    /**
     * Dashboard summary
     *
     * Returns the current high-level summary of the lending business.
     */
    #[QueryParameter('from', description: 'Only scope event-dated metrics on or after this date. Defaults to all-time.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('to', description: 'Only scope event-dated metrics on or before this date. Defaults to all-time.', type: 'string', example: '2026-08-26')]
    #[Response(200, description: 'Dashboard summary retrieved.', type: 'array{success: true, message: string, data: '.self::SUMMARY_SCHEMA.'}')]
    #[Response(401, description: 'Missing, invalid, or expired access token.', type: self::UNAUTHENTICATED_SCHEMA, examples: [[
        'success' => false,
        'message' => 'Unauthenticated',
    ]])]
    #[Response(403, description: 'Missing the dashboard.view permission.', type: self::FORBIDDEN_SCHEMA, examples: [[
        'success' => false,
        'message' => 'You do not have permission to perform this action.',
    ]])]
    #[Response(422, description: 'Validation failed (invalid date, or to before from).', type: self::VALIDATION_ERROR_SCHEMA, examples: [[
        'success' => false,
        'message' => 'The given data was invalid.',
        'errors' => ['to' => ['The to field must be a date after or equal to from.']],
    ]])]
    public function index(DashboardRequest $request): JsonResponse
    {
        $data = $this->dashboardService->summary($request->validated('from'), $request->validated('to'));

        return ApiResponse::success($data, 'Dashboard summary retrieved successfully');
    }
}
