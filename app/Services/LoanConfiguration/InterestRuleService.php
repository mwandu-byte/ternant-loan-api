<?php

namespace App\Services\LoanConfiguration;

use App\Exceptions\Loan\NoApplicableInterestRuleException;
use App\Models\InterestRule;
use App\Support\AmountRangeOverlap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class InterestRuleService
{
    public function list(): Collection
    {
        return InterestRule::query()->orderBy('minimum_amount')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): InterestRule
    {
        $data['status'] ??= 'active';
        $data['calculation_method'] ??= 'percentage';

        $this->assertNoActiveOverlap(
            (float) $data['minimum_amount'],
            isset($data['maximum_amount']) ? (float) $data['maximum_amount'] : null,
            ($data['status'] ?? 'active') === 'active',
        );

        return InterestRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(InterestRule $interestRule, array $data): InterestRule
    {
        $minimumAmount = (float) ($data['minimum_amount'] ?? $interestRule->minimum_amount);
        $maximumAmount = array_key_exists('maximum_amount', $data)
            ? ($data['maximum_amount'] !== null ? (float) $data['maximum_amount'] : null)
            : ($interestRule->maximum_amount !== null ? (float) $interestRule->maximum_amount : null);
        $status = $data['status'] ?? $interestRule->status;

        $this->assertNoActiveOverlap($minimumAmount, $maximumAmount, $status === 'active', $interestRule->id);

        $interestRule->update($data);

        return $interestRule;
    }

    public function delete(InterestRule $interestRule): void
    {
        $interestRule->delete();
    }

    /**
     * Resolve the active interest rule whose amount range covers the given
     * principal. Throws if no configured rule matches.
     */
    public function resolveApplicableRule(float $principal): InterestRule
    {
        $rule = InterestRule::query()
            ->active()
            ->where('minimum_amount', '<=', $principal)
            ->where(function ($query) use ($principal) {
                $query->whereNull('maximum_amount')->orWhere('maximum_amount', '>=', $principal);
            })
            ->first();

        if ($rule === null) {
            throw new NoApplicableInterestRuleException;
        }

        return $rule;
    }

    private function assertNoActiveOverlap(float $minimumAmount, ?float $maximumAmount, bool $isActive, ?int $ignoreId = null): void
    {
        if ($maximumAmount !== null && $maximumAmount <= $minimumAmount) {
            throw ValidationException::withMessages([
                'maximum_amount' => ['The maximum amount must be greater than the minimum amount.'],
            ]);
        }

        if (! $isActive) {
            return;
        }

        $query = InterestRule::query()->active();

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        foreach ($query->get(['minimum_amount', 'maximum_amount']) as $existing) {
            $existingMax = $existing->maximum_amount !== null ? (float) $existing->maximum_amount : null;

            if (AmountRangeOverlap::overlaps($minimumAmount, $maximumAmount, (float) $existing->minimum_amount, $existingMax)) {
                throw ValidationException::withMessages([
                    'minimum_amount' => ['This amount range overlaps with an existing active interest rule.'],
                ]);
            }
        }
    }
}
