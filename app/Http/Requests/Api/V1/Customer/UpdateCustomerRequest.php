<?php

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'identification_type' => ['sometimes', 'required', 'string', 'max:100'],
            'identification_number' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('customers')
                    ->where('business_id', $this->businessId())
                    ->where(
                        fn ($query) => $query->where('identification_type', $this->input('identification_type'))
                    )
                    ->ignore($this->route('customer')),
            ],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'required', 'string', 'max:2000'],
            /**
             * A new photograph to replace the customer's existing one. When
             * omitted, the existing photo is left untouched.
             *
             * @example null
             */
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('customer.photo_max_kb')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    private function businessId(): ?int
    {
        return $this->route('customer')?->business_id;
    }
}
