<?php

namespace App\Services\Business;

use App\Models\Business;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BusinessService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Business::query()->orderByDesc('created_at');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('registration_number', 'like', "%{$search}%"));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Business
    {
        // status and the two boolean flags fall back to DB-level defaults
        // when omitted; refresh so the returned model reflects them instead
        // of the nulls left over from the in-memory pre-insert state.
        return Business::create($data)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Business $business, array $data): Business
    {
        $business->update($data);

        return $business;
    }
}
