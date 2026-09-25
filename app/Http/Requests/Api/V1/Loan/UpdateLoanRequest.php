<?php

namespace App\Http\Requests\Api\V1\Loan;

use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'repayment_frequency' => ['sometimes', 'required', 'string', Rule::in(RepaymentFrequency::query()->forBusiness($this->loanBusinessId())->where('status', 'active')->pluck('code'))],
            'repayment_term' => ['sometimes', 'required', 'integer', Rule::in(RepaymentTerm::query()->forBusiness($this->loanBusinessId())->where('status', 'active')->pluck('value'))],
            'start_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['pending', 'active', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'collateral_ids' => ['sometimes', 'array'],
            'collateral_ids.*' => ['integer', 'exists:collaterals,id'],
            'payment_method' => ['nullable', 'string', Rule::in(config('payment.methods'))],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Frequencies and terms are configured per business: the ones on
     * offer are those of the loan's business.
     */
    private function loanBusinessId(): ?int
    {
        return Loan::query()->whereKey($this->route('loan'))->value('business_id');
    }
}
