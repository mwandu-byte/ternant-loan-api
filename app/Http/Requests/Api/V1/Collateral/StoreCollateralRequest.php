<?php

namespace App\Http\Requests\Api\V1\Collateral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCollateralRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'estimated_value' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
