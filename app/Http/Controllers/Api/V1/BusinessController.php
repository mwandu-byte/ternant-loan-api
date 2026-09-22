<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Business\StoreBusinessRequest;
use App\Http\Requests\Api\V1\Business\UpdateBusinessRequest;
use App\Http\Resources\Api\V1\BusinessResource;
use App\Http\Responses\ApiResponse;
use App\Models\Business;
use App\Services\Business\BusinessService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage businesses (tenants).
 *
 * Creating, listing and updating businesses — including the
 * `requires_application_fee` and `requires_guarantor` rules — is limited to
 * platform users (users not attached to any business). A business user can
 * only read their own business through `GET /business`.
 */
#[Group('Businesses')]
class BusinessController extends Controller
{
    public function __construct(
        private readonly BusinessService $businessService,
    ) {
        //
    }

    /**
     * List businesses
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Business::class);

        $paginator = $this->businessService->list($request->only(['search', 'status', 'per_page', 'page']));

        return ApiResponse::success([
            'businesses' => BusinessResource::collection($paginator)->resolve(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Businesses retrieved successfully');
    }

    /**
     * Create a business
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $this->authorize('create', Business::class);

        return ApiResponse::success(
            new BusinessResource($this->businessService->create($request->validated())),
            'Business created successfully',
            201,
        );
    }

    /**
     * Show a business
     */
    public function show(Business $business): JsonResponse
    {
        $this->authorize('view', $business);

        return ApiResponse::success(new BusinessResource($business), 'Business retrieved successfully');
    }

    /**
     * Show the authenticated user's own business
     */
    public function current(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        if ($business === null) {
            return ApiResponse::error('The requested resource was not found.', 404);
        }

        return ApiResponse::success(new BusinessResource($business), 'Business retrieved successfully');
    }

    /**
     * Update a business
     */
    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $this->authorize('update', $business);

        return ApiResponse::success(
            new BusinessResource($this->businessService->update($business, $request->validated())),
            'Business updated successfully',
        );
    }
}
