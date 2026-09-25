<?php

namespace App\Services\LoanConfiguration;

use App\Models\RepaymentFrequency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RepaymentFrequencyService
{
    public function list(): Collection
    {
        return RepaymentFrequency::query()->forUser(auth()->user())->orderBy('interval_value')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RepaymentFrequency
    {
        $data['status'] ??= 'active';

        return RepaymentFrequency::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RepaymentFrequency $repaymentFrequency, array $data): RepaymentFrequency
    {
        $repaymentFrequency->update($data);

        return $repaymentFrequency;
    }

    public function delete(RepaymentFrequency $repaymentFrequency): void
    {
        $repaymentFrequency->delete();
    }

    public function resolveActiveByCode(string $code, ?int $businessId): RepaymentFrequency
    {
        $frequency = RepaymentFrequency::query()->forBusiness($businessId)->active()->where('code', $code)->first();

        if ($frequency === null) {
            throw ValidationException::withMessages([
                'repayment_frequency' => ['The selected repayment frequency is not active or does not exist.'],
            ]);
        }

        return $frequency;
    }
}
