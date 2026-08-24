<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property int|null $users_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->map(fn ($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
            ])),
            'users_count' => $this->when(isset($this->users_count), fn () => $this->users_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
