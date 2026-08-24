<?php

namespace App\Http\Requests\Api\V1\PenaltyRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenaltyRuleRequest extends FormRequest
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
            'penalty_type' => ['sometimes', 'required', 'string', Rule::in(['fixed'])],
            'penalty_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'application_frequency' => ['sometimes', 'required', 'string', Rule::in(['once', 'daily', 'weekly', 'monthly'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
