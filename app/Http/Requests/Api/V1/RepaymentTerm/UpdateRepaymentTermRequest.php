<?php

namespace App\Http\Requests\Api\V1\RepaymentTerm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRepaymentTermRequest extends FormRequest
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
            'value' => ['sometimes', 'required', 'integer', 'min:1'],
            'unit' => ['sometimes', 'required', 'string', Rule::in(['days', 'weeks', 'months', 'years'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
