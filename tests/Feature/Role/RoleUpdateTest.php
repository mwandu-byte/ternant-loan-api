<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleUpdateTest extends TestCase
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
        $permissions[] = 'data.view-all';

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return JWTAuth::fromUser($user);
    }

    public function test_authorized_user_can_rename_a_role(): void
    {
        $role = Role::create(['name' => 'old_name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'new_name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('new_name', $role->fresh()->name);
    }

    public function test_duplicate_name_on_rename_is_rejected(): void
    {
        Role::create(['name' => 'taken_name', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'old_name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'taken_name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_renaming_to_its_own_current_name_does_not_fail_uniqueness(): void
    {
        $role = Role::create(['name' => 'same_name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'same_name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
    }

    public function test_permissions_are_not_modifiable_through_this_endpoint(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'old_name', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $this->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'new_name',
            'permissions' => ['loans.view'],
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_update_requires_authentication(): void
    {
        $role = Role::create(['name' => 'old_name', 'guard_name' => 'api']);

        $response = $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'new_name']);

        $response->assertStatus(401);
    }

    public function test_update_requires_roles_update_permission(): void
    {
        $role = Role::create(['name' => 'old_name', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/roles/{$role->id}", ['name' => 'new_name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_update_returns_404_for_nonexistent_role(): void
    {
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson('/api/v1/roles/999999', ['name' => 'new_name'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
