<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Reports\CollectionReportService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money IN through actual customer repayments. Sums Repayment.amount,
 * never Receipt.amount — a receipt can fan out across several schedule
 * installments, so summing receipts would double count.
 *
 * All endpoints return the application's standard envelope:
 * `{"success": bool, "message": string, "data"?: object|null, "errors"?: object}`.
 * All endpoints require `Authorization: Bearer {access_token}` plus the
 * `reports.view` permission.
 */
#[Group('Reports')]
class CollectionReportController extends Controller
{
    private const SUMMARY_SCHEMA = 'array{total_collected: string, number_of_repayments: int, total_receipts: int, collection_by_payment_method: array<string, string>}';

    private const ITEM_SCHEMA = 'array{receipt_number: string|null, repayment_reference: string, customer: array{id: int, full_name: string, phone: string}|null, loan_reference: string|null, installment_number: int|null, amount: string, payment_method: string|null, repayment_date: string, receipt_date: string|null, status: string|null, received_by: string|null}';

    private const PAGINATION_SCHEMA = 'array{current_page: int, per_page: int, total: int, last_page: int}';

    private const UNAUTHENTICATED_SCHEMA = 'array{success: false, message: string}';

    private const FORBIDDEN_SCHEMA = 'array{success: false, message: string}';

    public function __construct(
        private readonly CollectionReportService $reportService,
    ) {
        //
    }

    /**
     * Collection (repayment) report
     *
     * Returns collection totals alongside a paginated, filterable list of
     * individual repayments. `date_from`/`date_to` filter on
     * `repayment_date`. `status` filters by the related installment's
     * current status.
     */
    #[QueryParameter('date_from', description: 'Only repayments on or after this date.', type: 'string', example: '2026-08-01')]
    #[QueryParameter('date_to', description: 'Only repayments on or before this date.', type: 'string', example: '2026-08-31')]
    #[QueryParameter('status', description: 'Filter by the related installment\'s current status.', type: 'string', example: 'paid')]
    #[QueryParameter('customer_id', description: 'Filter by customer.', type: 'integer', example: 10)]
    #[QueryParameter('user_id', description: 'Filter by the user who received the payment.', type: 'integer', example: 3)]
    #[QueryParameter('page', description: 'Page number.', type: 'integer', default: 1)]
    #[QueryParameter('per_page', description: 'Results per page (max 100).', type: 'integer', default: 15)]
    #[Response(200, description: 'Collection report retrieved.', type: 'array{success: true, message: string, data: array{summary: '.self::SUMMARY_SCHEMA.', items: '.self::ITEM_SCHEMA.'[], pagination: '.self::PAGINATION_SCHEMA.'}}')]
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->reportService->generate($request->only([
            'date_from', 'date_to', 'status', 'customer_id', 'user_id', 'per_page', 'page',
        ]));

        return ApiResponse::success($report, 'Collection report retrieved successfully');
    }
}
