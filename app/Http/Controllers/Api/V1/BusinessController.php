<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Business\StoreBusinessRequest;
use App\Http\Requests\Api\V1\Business\UpdateBusinessRequest;
use App\Http\Requests\Api\V1\Business\UpdateCurrentBusinessRequest;
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
 * Creating, listing, suspending and updating arbitrary businesses is
 * limited to platform users (users not attached to any business). A
 * business user works only with their own business through `/business`:
 * anyone in it can read it, and holders of `business-settings.update` (the
 * business owner) can edit its details and its `requires_application_fee` /
 * `requires_guarantor` rules. Businesses usually come from self-registration
 * (`POST /auth/register`).
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
     * Update the authenticated user's own business
     *
     * Lets the business owner edit their business's details and loan
     * requirements (`requires_application_fee`, `requires_guarantor`). The
     * business `status` cannot be changed here; suspension is a platform
     * decision. Requires `business-settings.update`. Returns 404 for platform
     * users, who have no business of their own.
     */
    public function updateCurrent(UpdateCurrentBusinessRequest $request): JsonResponse
    {
        $business = $request->user()->business;

        if ($business === null) {
            return ApiResponse::error('The requested resource was not found.', 404);
        }

        return ApiResponse::success(
            new BusinessResource($this->businessService->update($business, $request->validated())),
            'Business updated successfully',
        );
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
