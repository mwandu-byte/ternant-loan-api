<?php

namespace App\Services\LoanConfiguration;

use App\Models\GracePeriod;

class GracePeriodService
{
    /**
     * The acting user's grace period: their business's, or the platform
     * default template for a platform user.
     */
    public function get(): GracePeriod
    {
        return $this->forBusiness(auth()->user()?->business_id);
    }

    public function forBusiness(?int $businessId): GracePeriod
    {
        return GracePeriod::query()->forBusiness($businessId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): GracePeriod
    {
        $gracePeriod = $this->get();
        $gracePeriod->update($data);

        return $gracePeriod;
    }
}
