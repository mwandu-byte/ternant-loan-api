<?php

namespace App\Http\Requests\Api\V1\RepaymentFrequency;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRepaymentFrequencyRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('repayment_frequencies')->ignore($this->route('frequency'))],
            'interval_value' => ['sometimes', 'required', 'integer', 'min:1'],
            'interval_unit' => ['sometimes', 'required', 'string', Rule::in(['day', 'week', 'month', 'year'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
