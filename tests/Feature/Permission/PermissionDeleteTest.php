<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_an_unused_permission(): void
    {
        $target = Permission::firstOrCreate(['name' => 'unused.permission', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.delete']);

        $response = $this->deleteJson("/api/v1/permissions/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseMissing('permissions', ['id' => $target->id]);
    }

    public function test_permission_assigned_to_a_role_cannot_be_deleted(): void
    {
        $target = Permission::firstOrCreate(['name' => 'in.use', 'guard_name' => 'api']);
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api'])->givePermissionTo($target);
        $token = $this->actingUserToken(['permissions.delete']);

        $response = $this->deleteJson("/api/v1/permissions/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This permission cannot be deleted because it is currently in use.',
        ]);
        $this->assertDatabaseHas('permissions', ['id' => $target->id]);
    }

    public function test_permission_assigned_directly_to_a_user_cannot_be_deleted(): void
    {
        $target = Permission::firstOrCreate(['name' => 'in.use', 'guard_name' => 'api']);
        User::factory()->create()->givePermissionTo($target);
        $token = $this->actingUserToken(['permissions.delete']);

        $response = $this->deleteJson("/api/v1/permissions/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This permission cannot be deleted because it is currently in use.',
        ]);
        $this->assertDatabaseHas('permissions', ['id' => $target->id]);
    }

    public function test_delete_requires_authentication(): void
    {
        $target = Permission::firstOrCreate(['name' => 'unused.permission', 'guard_name' => 'api']);

        $response = $this->deleteJson("/api/v1/permissions/{$target->id}");

        $response->assertStatus(401);
    }

    public function test_delete_requires_permissions_delete_permission(): void
    {
        $target = Permission::firstOrCreate(['name' => 'unused.permission', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson("/api/v1/permissions/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_delete_returns_404_for_nonexistent_permission(): void
    {
        $token = $this->actingUserToken(['permissions.delete']);

        $response = $this->deleteJson('/api/v1/permissions/999999', [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
