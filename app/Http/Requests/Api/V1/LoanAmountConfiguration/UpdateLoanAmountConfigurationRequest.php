<?php

namespace App\Http\Requests\Api\V1\LoanAmountConfiguration;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanAmountConfigurationRequest extends FormRequest
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
            'minimum_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'gt:minimum_amount'],
        ];
    }
}
