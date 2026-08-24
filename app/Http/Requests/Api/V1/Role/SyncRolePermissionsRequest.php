<?php

namespace App\Http\Requests\Api\V1\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncRolePermissionsRequest extends FormRequest
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
            'permissions' => ['present', 'array'],
            'permissions.*' => ['distinct', 'string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ];
    }
}
