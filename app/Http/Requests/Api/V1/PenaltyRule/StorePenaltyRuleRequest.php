<?php

namespace App\Http\Requests\Api\V1\PenaltyRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePenaltyRuleRequest extends FormRequest
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
            'penalty_type' => ['sometimes', 'required', 'string', Rule::in(['fixed'])],
            'penalty_value' => ['required', 'numeric', 'min:0'],
            'application_frequency' => ['required', 'string', Rule::in(['once', 'daily', 'weekly', 'monthly'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
