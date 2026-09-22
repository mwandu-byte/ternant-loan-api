<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Guarantor\StoreGuarantorRequest;
use App\Http\Requests\Api\V1\Guarantor\UpdateGuarantorRequest;
use App\Http\Resources\Api\V1\GuarantorResource;
use App\Http\Responses\ApiResponse;
use App\Services\Guarantor\GuarantorService;
use App\Services\Loan\LoanService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * Manage the guarantors of a loan.
 *
 * A loan may have any number of guarantors. A guarantor is either an
 * existing customer of the same business (`guarantor_customer_id`, whose
 * identity data is reused, never duplicated) or an inline person with
 * their own identification details. Guarantors can only be changed while
 * the loan is `pending`. When the business has `requires_guarantor`
 * enabled, a loan can never be left without at least one guarantor.
 * A user can only reach guarantors of loans in their own business.
 */
#[Group('Guarantors')]
class GuarantorController extends Controller
{
    public function __construct(
        private readonly GuarantorService $guarantorService,
        private readonly LoanService $loanService,
    ) {
        //
    }

    /**
     * List a loan's guarantors
     */
    public function index(int $loan): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $this->authorize('viewScope', $model);

        return ApiResponse::success([
            'guarantors' => GuarantorResource::collection($this->guarantorService->listForLoan($model))->resolve(),
        ], 'Guarantors retrieved successfully');
    }

    /**
     * Add a guarantor to a loan
     */
    public function store(int $loan, StoreGuarantorRequest $request): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $this->authorize('viewScope', $model);

        $guarantor = $this->guarantorService->add($model, $request->validated());

        return ApiResponse::success(new GuarantorResource($guarantor), 'Guarantor added successfully', 201);
    }

    /**
     * Show a guarantor
     */
    public function show(int $loan, int $guarantor): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $record = $this->guarantorService->find($model, $guarantor);
        $this->authorize('view', $record);

        return ApiResponse::success(new GuarantorResource($record), 'Guarantor retrieved successfully');
    }

    /**
     * Update a guarantor
     */
    public function update(int $loan, int $guarantor, UpdateGuarantorRequest $request): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $record = $this->guarantorService->find($model, $guarantor);
        $this->authorize('update', $record);

        $updated = $this->guarantorService->update($model, $record, $request->validated());

        return ApiResponse::success(new GuarantorResource($updated), 'Guarantor updated successfully');
    }

    /**
     * Remove a guarantor
     */
    public function destroy(int $loan, int $guarantor): JsonResponse
    {
        $model = $this->loanService->find($loan);
        $record = $this->guarantorService->find($model, $guarantor);
        $this->authorize('delete', $record);

        $this->guarantorService->delete($model, $record);

        return ApiResponse::success(null, 'Guarantor removed successfully');
    }
}
