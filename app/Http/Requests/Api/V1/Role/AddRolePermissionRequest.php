<?php

namespace App\Http\Requests\Api\V1\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddRolePermissionRequest extends FormRequest
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
            'permission' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ];
    }
}
