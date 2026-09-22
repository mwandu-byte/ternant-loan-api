<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ApplicationFee\StoreApplicationFeeRequest;
use App\Http\Resources\Api\V1\ApplicationFeeResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\ApplicationFee\ApplicationFeeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage customer application fees.
 *
 * When a business has `requires_application_fee` enabled, a loan can only
 * be created for a customer who has a paid application fee that no other
 * loan has consumed; creating the loan links that fee to it. Fees belong
 * to the customer's business and are invisible to every other business.
 */
#[Group('Application Fees')]
class ApplicationFeeController extends Controller
{
    public function __construct(
        private readonly ApplicationFeeService $applicationFeeService,
    ) {
        //
    }

    /**
     * List a customer's application fees
     */
    public function index(Customer $customer, Request $request): JsonResponse
    {
        $this->authorize('viewScope', $customer);

        $paginator = $this->applicationFeeService->listForCustomer($customer, $request->only(['status', 'per_page', 'page']));

        return ApiResponse::success([
            'application_fees' => ApplicationFeeResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Application fees retrieved successfully');
    }

    /**
     * Record an application fee
     */
    public function store(Customer $customer, StoreApplicationFeeRequest $request): JsonResponse
    {
        $this->authorize('viewScope', $customer);

        $fee = $this->applicationFeeService->create($customer, $request->validated());

        return ApiResponse::success(new ApplicationFeeResource($fee), 'Application fee recorded successfully', 201);
    }

    /**
     * Show an application fee
     */
    public function show(int $applicationFee): JsonResponse
    {
        $fee = $this->applicationFeeService->find($applicationFee);
        $this->authorize('view', $fee);

        return ApiResponse::success(new ApplicationFeeResource($fee), 'Application fee retrieved successfully');
    }
}
