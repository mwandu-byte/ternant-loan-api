<?php

namespace App\Http\Requests\Api\V1\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A business owner editing their own business. `status` is deliberately
 * absent: suspending or reactivating a business is a platform decision.
 */
class UpdateCurrentBusinessRequest extends FormRequest
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
        $businessId = $this->user()?->business_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100', Rule::unique('businesses', 'registration_number')->ignore($businessId)],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('businesses', 'email')->ignore($businessId)],
            'address' => ['nullable', 'string', 'max:2000'],
            /** Whether every new loan needs a paid, unused application fee on file for the customer. */
            'requires_application_fee' => ['sometimes', 'boolean'],
            /** Whether every new loan needs at least one guarantor. */
            'requires_guarantor' => ['sometimes', 'boolean'],
        ];
    }
}
