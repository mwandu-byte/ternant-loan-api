<?php

namespace App\Services\LoanConfiguration;

use App\Models\PenaltyRule;
use App\Support\AmountRangeOverlap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PenaltyRuleService
{
    public function list(): Collection
    {
        return PenaltyRule::query()->orderBy('minimum_amount')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PenaltyRule
    {
        $data['status'] ??= 'active';
        $data['penalty_type'] ??= 'fixed';

        $this->assertNoActiveOverlap(
            (float) $data['minimum_amount'],
            isset($data['maximum_amount']) ? (float) $data['maximum_amount'] : null,
            ($data['status'] ?? 'active') === 'active',
        );

        return PenaltyRule::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PenaltyRule $penaltyRule, array $data): PenaltyRule
    {
        $minimumAmount = (float) ($data['minimum_amount'] ?? $penaltyRule->minimum_amount);
        $maximumAmount = array_key_exists('maximum_amount', $data)
            ? ($data['maximum_amount'] !== null ? (float) $data['maximum_amount'] : null)
            : ($penaltyRule->maximum_amount !== null ? (float) $penaltyRule->maximum_amount : null);
        $status = $data['status'] ?? $penaltyRule->status;

        $this->assertNoActiveOverlap($minimumAmount, $maximumAmount, $status === 'active', $penaltyRule->id);

        $penaltyRule->update($data);

        return $penaltyRule;
    }

    public function delete(PenaltyRule $penaltyRule): void
    {
        $penaltyRule->delete();
    }

    /**
     * Resolves the active penalty rule covering the loan's ORIGINAL
     * principal amount — never outstanding balance, remaining
     * installment amount, or interest — per the approved business rule.
     * Called only from the penalty accrual path (never from loan
     * creation, which must remain unaffected by penalty configuration).
     * Returns null, not an exception, when no active rule matches: an
     * unmatched principal simply means that schedule's accrual is
     * skipped for this run, not that the whole batch fails.
     */
    public function resolveApplicableRule(float $principal): ?PenaltyRule
    {
        return PenaltyRule::query()
            ->active()
            ->where('minimum_amount', '<=', $principal)
            ->where(function ($query) use ($principal) {
                $query->whereNull('maximum_amount')->orWhere('maximum_amount', '>=', $principal);
            })
            ->first();
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

        $query = PenaltyRule::query()->active();

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        foreach ($query->get(['minimum_amount', 'maximum_amount']) as $existing) {
            $existingMax = $existing->maximum_amount !== null ? (float) $existing->maximum_amount : null;

            if (AmountRangeOverlap::overlaps($minimumAmount, $maximumAmount, (float) $existing->minimum_amount, $existingMax)) {
                throw ValidationException::withMessages([
                    'minimum_amount' => ['This amount range overlaps with an existing active penalty rule.'],
                ]);
            }
        }
    }
}
