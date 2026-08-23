<?php

namespace App\Http\Requests\Api\V1\Collateral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollateralRequest extends FormRequest
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
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'estimated_value' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
