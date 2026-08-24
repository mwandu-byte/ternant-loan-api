<?php

namespace App\Services\LoanConfiguration;

use App\Models\RepaymentTerm;
use Illuminate\Database\Eloquent\Collection;

class RepaymentTermService
{
    public function list(): Collection
    {
        return RepaymentTerm::query()->orderBy('value')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RepaymentTerm
    {
        $data['status'] ??= 'active';

        return RepaymentTerm::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RepaymentTerm $repaymentTerm, array $data): RepaymentTerm
    {
        $repaymentTerm->update($data);

        return $repaymentTerm;
    }

    public function delete(RepaymentTerm $repaymentTerm): void
    {
        $repaymentTerm->delete();
    }
}
