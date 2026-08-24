<?php

namespace App\Services\LoanConfiguration;

use App\Models\GracePeriod;

class GracePeriodService
{
    public function get(): GracePeriod
    {
        return GracePeriod::query()->firstOrFail();
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
