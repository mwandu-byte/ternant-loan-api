<?php

namespace App\Services\LoanConfiguration;

use App\Exceptions\Loan\LoanAmountOutOfRangeException;
use App\Models\LoanAmountConfiguration;
use Illuminate\Validation\ValidationException;

class LoanAmountConfigurationService
{
    public function get(): LoanAmountConfiguration
    {
        return LoanAmountConfiguration::query()->firstOrFail();
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
     * Assert the given principal falls within the configured global loan
     * amount range. A null maximum means there is no upper limit.
     */
    public function assertWithinRange(float $principal): void
    {
        $configuration = $this->get();

        $minimum = (float) $configuration->minimum_amount;
        $maximum = $configuration->maximum_amount !== null ? (float) $configuration->maximum_amount : null;

        if ($principal < $minimum || ($maximum !== null && $principal > $maximum)) {
            throw new LoanAmountOutOfRangeException;
        }
    }
}
