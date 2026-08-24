<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RolePermissionAssignmentTest extends TestCase
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

    // -----------------------------------------------------------------
    // PUT /roles/{role}/permissions (sync)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_sync_permissions(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$target->id}/permissions", [
            'permissions' => ['loans.view', 'loans.create'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(['loans.view', 'loans.create'], $target->fresh()->permissions->pluck('name')->all());
    }

    public function test_sync_removes_permissions_not_included(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target->givePermissionTo(['loans.view', 'loans.create']);
        $token = $this->actingUserToken(['roles.update']);

        $this->putJson("/api/v1/roles/{$target->id}/permissions", [
            'permissions' => ['loans.view'],
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertEqualsCanonicalizing(['loans.view'], $target->fresh()->permissions->pluck('name')->all());
    }

    public function test_sync_rejects_unknown_permission_name(): void
    {
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$target->id}/permissions", [
            'permissions' => ['does.not.exist'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_sync_rejects_permission_from_wrong_guard(): void
    {
        Permission::firstOrCreate(['name' => 'web.only', 'guard_name' => 'web']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->putJson("/api/v1/roles/{$target->id}/permissions", [
            'permissions' => ['web.only'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_sync_stripping_the_only_roles_update_grant_is_blocked(): void
    {
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'coverage-role', 'guard_name' => 'api']);
        $target->givePermissionTo('roles.update');
        $holder = User::factory()->create();
        $holder->assignRole($target);
        $token = JWTAuth::fromUser($holder);

        $response = $this->putJson("/api/v1/roles/{$target->id}/permissions", [
            'permissions' => [],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
        ]);
        $this->assertTrue($target->fresh()->hasPermissionTo('roles.update'));
    }

    public function test_sync_requires_roles_update_permission(): void
    {
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/roles/{$target->id}/permissions", ['permissions' => []], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // POST /roles/{role}/permissions (add single)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_add_a_single_permission(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->postJson("/api/v1/roles/{$target->id}/permissions", [
            'permission' => 'loans.view',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertTrue($target->fresh()->hasPermissionTo('loans.view'));
    }

    public function test_adding_an_already_held_permission_is_a_no_op(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target->givePermissionTo('loans.view');
        $token = $this->actingUserToken(['roles.update']);

        $this->postJson("/api/v1/roles/{$target->id}/permissions", [
            'permission' => 'loans.view',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertCount(1, $target->fresh()->permissions);
    }

    public function test_add_rejects_unknown_permission_name(): void
    {
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->postJson("/api/v1/roles/{$target->id}/permissions", [
            'permission' => 'does.not.exist',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permission']);
        $this->assertDatabaseMissing('permissions', ['name' => 'does.not.exist']);
    }

    // -----------------------------------------------------------------
    // DELETE /roles/{role}/permissions/{permission} (revoke single)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_revoke_a_permission(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target->givePermissionTo(['loans.view', 'loans.create']);
        $viewPermission = Permission::where('name', 'loans.view')->where('guard_name', 'api')->first();
        $token = $this->actingUserToken(['roles.update']);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}/permissions/{$viewPermission->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->hasPermissionTo('loans.view'));
        $this->assertTrue($target->fresh()->hasPermissionTo('loans.create'));
        $this->assertDatabaseHas('permissions', ['id' => $viewPermission->id]);
    }

    public function test_revoking_the_only_roles_update_grant_is_blocked(): void
    {
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'coverage-role', 'guard_name' => 'api']);
        $target->givePermissionTo('roles.update');
        $rolesUpdatePermission = Permission::where('name', 'roles.update')->where('guard_name', 'api')->first();
        $holder = User::factory()->create();
        $holder->assignRole($target);
        $token = JWTAuth::fromUser($holder);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}/permissions/{$rolesUpdatePermission->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
        $this->assertTrue($target->fresh()->hasPermissionTo('roles.update'));
    }

    public function test_revoke_requires_roles_update_permission(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $target = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target->givePermissionTo('loans.view');
        $viewPermission = Permission::where('name', 'loans.view')->where('guard_name', 'api')->first();
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson("/api/v1/roles/{$target->id}/permissions/{$viewPermission->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }
}
