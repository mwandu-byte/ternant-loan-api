<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingUserToken(array $permissions): string
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_rename_a_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'old.name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.update']);

        $response = $this->putJson("/api/v1/permissions/{$permission->id}", ['name' => 'new.name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('new.name', $permission->fresh()->name);
    }

    public function test_duplicate_name_on_rename_is_rejected(): void
    {
        Permission::firstOrCreate(['name' => 'taken.name', 'guard_name' => 'api']);
        $permission = Permission::firstOrCreate(['name' => 'old.name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.update']);

        $response = $this->putJson("/api/v1/permissions/{$permission->id}", ['name' => 'taken.name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_update_requires_authentication(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'old.name', 'guard_name' => 'api']);

        $response = $this->putJson("/api/v1/permissions/{$permission->id}", ['name' => 'new.name']);

        $response->assertStatus(401);
    }

    public function test_update_requires_permissions_update_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'old.name', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/permissions/{$permission->id}", ['name' => 'new.name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_update_returns_404_for_nonexistent_permission(): void
    {
        $token = $this->actingUserToken(['permissions.update']);

        $response = $this->putJson('/api/v1/permissions/999999', ['name' => 'new.name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
