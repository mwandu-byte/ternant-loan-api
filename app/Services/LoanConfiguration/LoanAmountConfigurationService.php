<?php

namespace App\Services\LoanConfiguration;

use App\Exceptions\Loan\LoanAmountOutOfRangeException;
use App\Models\LoanAmountConfiguration;
use Illuminate\Validation\ValidationException;

class LoanAmountConfigurationService
{
    /**
     * The acting user's configuration: their business's, or the platform
     * default template for a platform user.
     */
    public function get(): LoanAmountConfiguration
    {
        return $this->forBusiness(auth()->user()?->business_id);
    }

    public function forBusiness(?int $businessId): LoanAmountConfiguration
    {
        return LoanAmountConfiguration::query()->forBusiness($businessId)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): LoanAmountConfiguration
    {
        $configuration = $this->get();

        $minimumAmount = (float) ($data['minimum_amount'] ?? $configuration->minimum_amount);
        $maximumAmount = array_key_exists('maximum_amount', $data)
            ? ($data['maximum_amount'] !== null ? (float) $data['maximum_amount'] : null)
            : ($configuration->maximum_amount !== null ? (float) $configuration->maximum_amount : null);

        if ($maximumAmount !== null && $maximumAmount <= $minimumAmount) {
            throw ValidationException::withMessages([
                'maximum_amount' => ['The maximum amount must be greater than the minimum amount.'],
            ]);
        }

        $configuration->update($data);

        return $configuration;
    }

    /**
     * Assert the given principal falls within the business's configured
     * loan amount range. A null maximum means there is no upper limit.
     */
    public function assertWithinRange(float $principal, ?int $businessId): void
    {
        $configuration = $this->forBusiness($businessId);

        $minimum = (float) $configuration->minimum_amount;
        $maximum = $configuration->maximum_amount !== null ? (float) $configuration->maximum_amount : null;

        if ($principal < $minimum || ($maximum !== null && $principal > $maximum)) {
            throw new LoanAmountOutOfRangeException;
        }
    }
}
