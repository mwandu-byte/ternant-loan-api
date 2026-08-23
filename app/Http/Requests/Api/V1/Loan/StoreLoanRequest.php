<?php

namespace App\Http\Requests\Api\V1\Loan;

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
            'repayment_frequency' => ['required', 'string', Rule::in(config('loan.repayment_frequencies'))],
            'repayment_term' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'active'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'collateral_ids' => ['nullable', 'array'],
            'collateral_ids.*' => ['integer', 'exists:collaterals,id'],
        ];
    }
}
