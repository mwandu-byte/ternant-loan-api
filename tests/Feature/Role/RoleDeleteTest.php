<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_an_unassigned_role(): void
    {
        // The acting user's own role grants roles.delete + roles.update,
        // so system-wide coverage survives regardless of this deletion.
        $target = Role::create(['name' => 'unused_role', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.delete', 'roles.update']);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseMissing('roles', ['id' => $target->id]);
    }

    public function test_deleting_a_permission_does_not_delete_the_permission_record(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'unused_role', 'guard_name' => 'api']);
        $target->givePermissionTo('loans.view');
        $token = $this->actingUserToken(['roles.delete', 'roles.update']);

        $this->deleteJson("/api/v1/roles/{$target->id}", [], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertDatabaseHas('permissions', ['name' => 'loans.view']);
    }

    public function test_role_assigned_to_users_cannot_be_deleted(): void
    {
        $target = Role::create(['name' => 'in_use_role', 'guard_name' => 'api']);
        User::factory()->create()->assignRole($target);
        $token = $this->actingUserToken(['roles.delete', 'roles.update']);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This role cannot be deleted because it is assigned to one or more users.',
        ]);
        $this->assertDatabaseHas('roles', ['id' => $target->id]);
        $this->assertTrue($target->fresh()->users()->exists());
    }

    public function test_role_deletion_is_blocked_when_no_enabled_user_would_hold_roles_update(): void
    {
        // No user in the system holds roles.update at all, so even
        // deleting an unrelated, unassigned role must be rejected.
        $target = Role::create(['name' => 'unused_role', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.delete']);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
        ]);
        $this->assertDatabaseHas('roles', ['id' => $target->id]);
    }

    public function test_delete_requires_authentication(): void
    {
        $target = Role::create(['name' => 'unused_role', 'guard_name' => 'api']);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}");

        $response->assertStatus(401);
    }

    public function test_delete_requires_roles_delete_permission(): void
    {
        $target = Role::create(['name' => 'unused_role', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_delete_returns_404_for_nonexistent_role(): void
    {
        $token = $this->actingUserToken(['roles.delete']);

        $response = $this->deleteJson('/api/v1/roles/999999', [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
