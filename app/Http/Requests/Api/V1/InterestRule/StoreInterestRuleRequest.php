<?php

namespace App\Http\Requests\Api\V1\InterestRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInterestRuleRequest extends FormRequest
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
            'minimum_amount' => ['required', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'gt:minimum_amount'],
            'interest_rate' => ['required', 'numeric', 'min:0'],
            'calculation_method' => ['sometimes', 'required', 'string', Rule::in(['percentage'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
