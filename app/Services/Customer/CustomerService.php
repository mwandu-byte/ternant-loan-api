<?php

namespace App\Services\Customer;

use App\Exceptions\Customer\CustomerHasRelatedRecordsException;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    private const SORTABLE_COLUMNS = ['full_name', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Customer::query()->visibleTo(auth()->user());

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('identification_number', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sortBy = in_array($filters['sort_by'] ?? null, self::SORTABLE_COLUMNS, true)
            ? $filters['sort_by']
            : 'created_at';

        $sortDir = ($filters['sort_dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = (int) ($filters['page'] ?? 1);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $photo = null): Customer
    {
        // created_by must never be client-supplied — it is always the
        // authenticated agent, regardless of what the request contained.
        unset($data['created_by']);
        $data['created_by'] = auth()->id();

        $data['phone'] = PhoneNumber::normalize($data['phone'], config('customer.default_country_code'));

        // Tenant users are always pinned to their own business (see
        // BelongsToBusiness); only platform users may choose one.
        $data['business_id'] = auth()->user()->business_id ?? ($data['business_id'] ?? null);

        $this->assertPhoneIsUnique($data['phone'], $data['business_id']);

        if ($photo !== null) {
            $data['photo'] = $this->storePhoto($photo);
        }

        return Customer::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data, ?UploadedFile $photo = null): Customer
    {
        // Ownership and tenancy are never reassignable via update.
        unset($data['created_by'], $data['business_id']);

        if (array_key_exists('phone', $data)) {
            $data['phone'] = PhoneNumber::normalize($data['phone'], config('customer.default_country_code'));
            $this->assertPhoneIsUnique($data['phone'], $customer->business_id, $customer->id);
        }

        if ($photo !== null) {
            $previousPhoto = $customer->photo;
            $data['photo'] = $this->storePhoto($photo);
        }

        $customer->update($data);

        if (isset($previousPhoto) && $previousPhoto) {
            Storage::disk(config('customer.photo_disk'))->delete($previousPhoto);
        }

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        $photo = $customer->photo;

        try {
            $customer->delete();
        } catch (QueryException $e) {
            throw new CustomerHasRelatedRecordsException;
        }

        if ($photo) {
            Storage::disk(config('customer.photo_disk'))->delete($photo);
        }
    }

    private function assertPhoneIsUnique(string $normalizedPhone, ?int $businessId, ?int $ignoreId = null): void
    {
        $query = Customer::where('phone', $normalizedPhone)->where('business_id', $businessId);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'This phone number is already registered.',
            ]);
        }
    }

    private function storePhoto(UploadedFile $photo): string
    {
        return $photo->store('customers', config('customer.photo_disk'));
    }
}
