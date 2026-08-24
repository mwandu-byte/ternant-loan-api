<?php

namespace App\Http\Requests\Api\V1\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Deliberately has no `roles` rule at all — role assignment is a
 * separate responsibility handled by the dedicated /users/{user}/roles
 * endpoints, so a `roles` key in the request body is never validated
 * and never reaches validated()/safe() data passed to the service.
 */
class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['sometimes', 'confirmed', Password::defaults()],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }
}
