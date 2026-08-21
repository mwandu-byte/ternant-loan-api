<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            /**
             * At least 10 characters, including uppercase, lowercase, a number, and
             * a symbol. In production, also rejected if found in a known data breach.
             *
             * @example "NewStrongPassword123!"
             */
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
