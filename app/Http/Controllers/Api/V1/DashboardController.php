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
 * `period`, `loan_performance`, and `charts` use their own separate,
 * always-bounded effective date range (`from`/`to` if supplied, else a
 * trailing default window) — echoed back as `period` — so trend charts
 * are never literally unbounded; this never narrows the five original
 * blocks' all-time-by-default totals. `charts.penalty_trend` reports only
 * accrued amounts: nothing in the schema ever marks a penalty as paid, so
 * no paid/outstanding split is fabricated for it.
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

    private const PERIOD_SCHEMA = 'array{date_from: string, date_to: string}';

    private const LOAN_PERFORMANCE_SCHEMA = 'array{total_loans: int, active_loans: int, completed_loans: int, overdue_loans: int, overdue_percentage: float, total_disbursed: string, total_collected: string, total_outstanding: string, total_interest_generated: string, total_penalties_accrued: string}';

    private const LOAN_STATUS_CHART_SCHEMA = 'array{status: string, count: int}';

    private const LOAN_PERFORMANCE_TREND_CHART_SCHEMA = 'array{period: string, loans: int, disbursed: string}';

    private const COLLECTION_TREND_CHART_SCHEMA = 'array{period: string, amount: string, repayments: int}';

    private const DISBURSEMENT_TREND_CHART_SCHEMA = 'array{period: string, amount: string, disbursements: int}';

    private const PAID_VS_OUTSTANDING_CHART_SCHEMA = 'array{category: string, amount: string}';

    private const PENALTY_TREND_CHART_SCHEMA = 'array{period: string, accrued: string}';

    private const CHARTS_SCHEMA = 'array{loan_status: '.self::LOAN_STATUS_CHART_SCHEMA.'[], loan_performance_trend: '.self::LOAN_PERFORMANCE_TREND_CHART_SCHEMA.'[], collection_trend: '.self::COLLECTION_TREND_CHART_SCHEMA.'[], disbursement_trend: '.self::DISBURSEMENT_TREND_CHART_SCHEMA.'[], paid_vs_outstanding: '.self::PAID_VS_OUTSTANDING_CHART_SCHEMA.'[], penalty_trend: '.self::PENALTY_TREND_CHART_SCHEMA.'[]}';

    private const SUMMARY_SCHEMA = 'array{customers: '.self::CUSTOMERS_SCHEMA.', loans: '.self::LOANS_SCHEMA.', financial: '.self::FINANCIAL_SCHEMA.', repayments: '.self::REPAYMENTS_SCHEMA.', payments: '.self::PAYMENTS_SCHEMA.', period: '.self::PERIOD_SCHEMA.', loan_performance: '.self::LOAN_PERFORMANCE_SCHEMA.', charts: '.self::CHARTS_SCHEMA.'}';

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
