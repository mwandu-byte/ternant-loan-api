<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserRoleAssignmentTest extends TestCase
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

    // -----------------------------------------------------------------
    // PUT /users/{user}/roles (sync)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_sync_roles(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Role::create(['name' => 'auditor', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}/roles", [
            'roles' => ['loan_officer', 'auditor'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(['loan_officer', 'auditor'], $target->fresh()->getRoleNames()->all());
    }

    public function test_sync_removes_roles_not_included(): void
    {
        $officer = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Role::create(['name' => 'auditor', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $target->assignRole($officer);
        $token = $this->actingUserToken(['users.update']);

        $this->putJson("/api/v1/users/{$target->id}/roles", [
            'roles' => ['auditor'],
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertEqualsCanonicalizing(['auditor'], $target->fresh()->getRoleNames()->all());
    }

    public function test_sync_rejects_unknown_role_name(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}/roles", [
            'roles' => ['does_not_exist'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['roles.0']);
    }

    public function test_sync_cannot_be_used_on_own_account(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['users.update']);
        $self = User::factory()->create();
        $self->assignRole($role);
        $token = JWTAuth::fromUser($self);

        $response = $this->putJson("/api/v1/users/{$self->id}/roles", [
            'roles' => ['loan_officer'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You cannot modify your own role assignments.',
        ]);
    }

    public function test_sync_stripping_the_only_roles_update_grant_is_blocked(): void
    {
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $coverageRole = Role::create(['name' => 'coverage-role', 'guard_name' => 'api']);
        $coverageRole->givePermissionTo('roles.update');
        Role::create(['name' => 'plain-role', 'guard_name' => 'api']);

        $target = User::factory()->create();
        $target->assignRole($coverageRole);

        // Acting user does not hold roles.update itself.
        $token = $this->actingUserToken(['users.update']);

        $response = $this->putJson("/api/v1/users/{$target->id}/roles", [
            'roles' => ['plain-role'],
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
        ]);
        $this->assertTrue($target->fresh()->hasRole($coverageRole));
    }

    public function test_sync_requires_users_update_permission(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => []], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // POST /users/{user}/roles (add single)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_add_a_single_role(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.update']);

        $response = $this->postJson("/api/v1/users/{$target->id}/roles", [
            'role' => 'loan_officer',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertTrue($target->fresh()->hasRole('loan_officer'));
    }

    public function test_adding_an_already_held_role_is_a_no_op(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $target->assignRole($role);
        $token = $this->actingUserToken(['users.update']);

        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'role' => 'loan_officer',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $this->assertCount(1, $target->fresh()->roles);
    }

    public function test_add_role_cannot_be_used_on_own_account(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['users.update']);
        $self = User::factory()->create();
        $self->assignRole($role);
        $token = JWTAuth::fromUser($self);

        $response = $this->postJson("/api/v1/users/{$self->id}/roles", [
            'role' => 'loan_officer',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // DELETE /users/{user}/roles/{role} (remove single)
    // -----------------------------------------------------------------

    public function test_authorized_user_can_remove_a_role(): void
    {
        $officer = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $auditor = Role::create(['name' => 'auditor', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $target->assignRole($officer);
        $target->assignRole($auditor);
        $token = $this->actingUserToken(['users.update']);

        $response = $this->deleteJson("/api/v1/users/{$target->id}/roles/{$officer->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->hasRole('loan_officer'));
        $this->assertTrue($target->fresh()->hasRole('auditor'));
        $this->assertDatabaseHas('roles', ['id' => $officer->id]);
    }

    public function test_remove_role_cannot_be_used_on_own_account(): void
    {
        $loanOfficerRole = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'users.update', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['users.update']);
        $self = User::factory()->create();
        $self->assignRole($role);
        $self->assignRole($loanOfficerRole);
        $token = JWTAuth::fromUser($self);

        $response = $this->deleteJson("/api/v1/users/{$self->id}/roles/{$loanOfficerRole->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_removing_the_only_roles_update_grant_is_blocked(): void
    {
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $coverageRole = Role::create(['name' => 'coverage-role', 'guard_name' => 'api']);
        $coverageRole->givePermissionTo('roles.update');

        $target = User::factory()->create();
        $target->assignRole($coverageRole);

        $token = $this->actingUserToken(['users.update']);

        $response = $this->deleteJson("/api/v1/users/{$target->id}/roles/{$coverageRole->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409);
        $this->assertTrue($target->fresh()->hasRole($coverageRole));
    }
}
