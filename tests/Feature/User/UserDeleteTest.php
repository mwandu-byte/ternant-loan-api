<?php

namespace Tests\Feature\User;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_a_user(): void
    {
        // Acting user also holds roles.update so deleting an unrelated
        // target never trips the administrative-coverage guard.
        $target = User::factory()->create();
        $token = $this->actingUserToken(['users.delete', 'roles.update']);

        $response = $this->deleteJson("/api/v1/users/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_self_deletion_is_always_blocked(): void
    {
        Permission::firstOrCreate(['name' => 'users.delete', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['users.delete', 'roles.update']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $token = JWTAuth::fromUser($user);

        $response = $this->deleteJson("/api/v1/users/{$user->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'You cannot delete your own account.',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_the_last_enabled_roles_update_holder_is_blocked(): void
    {
        $rolesUpdateRole = Role::create(['name' => 'coverage-role', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'roles.update', 'guard_name' => 'api']);
        $rolesUpdateRole->givePermissionTo('roles.update');

        $target = User::factory()->create();
        $target->assignRole($rolesUpdateRole);

        // Acting user can delete but does NOT hold roles.update itself.
        $token = $this->actingUserToken(['users.delete']);

        $response = $this->deleteJson("/api/v1/users/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.',
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_deleting_a_user_with_related_financial_records_is_blocked(): void
    {
        $target = User::factory()->create();
        Payment::factory()->create(['paid_by' => $target->id]);
        $token = $this->actingUserToken(['users.delete', 'roles.update']);

        $response = $this->deleteJson("/api/v1/users/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This user cannot be deleted because related financial records exist.',
        ]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_delete_requires_authentication(): void
    {
        $target = User::factory()->create();

        $response = $this->deleteJson("/api/v1/users/{$target->id}");

        $response->assertStatus(401);
    }

    public function test_delete_requires_users_delete_permission(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson("/api/v1/users/{$target->id}", [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_delete_returns_404_for_nonexistent_user(): void
    {
        $token = $this->actingUserToken(['users.delete']);

        $response = $this->deleteJson('/api/v1/users/999999', [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
