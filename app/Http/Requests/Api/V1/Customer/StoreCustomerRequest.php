<?php

namespace App\Http\Requests\Api\V1\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'identification_type' => ['required', 'string', 'max:100'],
            'identification_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('customers')->where(
                    fn ($query) => $query->where('identification_type', $this->input('identification_type'))
                ),
            ],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:2000'],
            /**
             * The customer's photograph. Stored via the filesystem; only the
             * resulting path is persisted, never the binary contents.
             *
             * @example null
             */
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('customer.photo_max_kb')],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
