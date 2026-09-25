<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBusinessRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100', 'unique:businesses,registration_number'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', 'unique:businesses,email'],
            'address' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
            'requires_application_fee' => ['sometimes', 'boolean'],
            'requires_guarantor' => ['sometimes', 'boolean'],
        ];
    }
}
