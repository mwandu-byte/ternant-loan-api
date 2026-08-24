<?php

namespace App\Http\Requests\Api\V1\GracePeriod;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGracePeriodRequest extends FormRequest
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
            'duration' => ['sometimes', 'required', 'integer', 'min:1'],
            'unit' => ['sometimes', 'required', 'string', Rule::in(['days', 'weeks', 'months'])],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
