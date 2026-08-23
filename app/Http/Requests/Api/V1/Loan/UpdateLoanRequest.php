<?php

namespace App\Http\Requests\Api\V1\Loan;

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
            'repayment_frequency' => ['sometimes', 'required', 'string', Rule::in(config('loan.repayment_frequencies'))],
            'repayment_term' => ['sometimes', 'required', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['pending', 'active', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'collateral_ids' => ['sometimes', 'array'],
            'collateral_ids.*' => ['integer', 'exists:collaterals,id'],
        ];
    }
}
