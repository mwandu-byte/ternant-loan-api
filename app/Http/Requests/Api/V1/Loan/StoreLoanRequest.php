<?php

namespace App\Http\Requests\Api\V1\Loan;

use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
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
            'principal_amount' => ['required', 'numeric', 'min:0.01'],
            'repayment_frequency' => ['required', 'string', Rule::in(RepaymentFrequency::query()->where('status', 'active')->pluck('code'))],
            'repayment_term' => ['required', 'integer', Rule::in(RepaymentTerm::query()->where('status', 'active')->pluck('value'))],
            'start_date' => ['required', 'date'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'active'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'has_discount' => ['nullable', 'boolean'],
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'required_if:has_discount,true', 'prohibited_unless:has_discount,true'],
            'collateral_ids' => ['nullable', 'array'],
            'collateral_ids.*' => ['integer', 'exists:collaterals,id'],
        ];
    }
}
