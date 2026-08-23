<?php

namespace App\Services\Collateral;

use App\Exceptions\Collateral\CollateralNotFoundException;
use App\Models\Collateral;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CollateralService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(Customer $customer, array $filters): LengthAwarePaginator
    {
        $query = $customer->collaterals();

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('type', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function findForCustomer(Customer $customer, int $id): Collateral
    {
        $collateral = $customer->collaterals()->find($id);

        if ($collateral === null) {
            throw new CollateralNotFoundException;
        }

        return $collateral;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data): Collateral
    {
        unset($data['customer_id']);

        return $customer->collaterals()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Collateral $collateral, array $data): Collateral
    {
        unset($data['customer_id']);

        $collateral->update($data);

        return $collateral;
    }

    public function delete(Collateral $collateral): void
    {
        $collateral->delete();
    }
}
