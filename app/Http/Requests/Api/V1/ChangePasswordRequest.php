<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            /**
             * Must differ from current_password. At least 10 characters, including
             * uppercase, lowercase, a number, and a symbol. In production, also
             * rejected if found in a known data breach.
             *
             * @example "NewStrongPassword123!"
             */
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ];
    }
}
