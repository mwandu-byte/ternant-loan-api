<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_role(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson("/api/v1/roles/{$role->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $role->id, 'name' => 'loan_officer', 'guard_name' => 'api'],
        ]);
    }

    public function test_show_response_contains_exact_expected_fields(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson("/api/v1/roles/{$role->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'guard_name', 'permissions', 'users_count', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
    }

    public function test_show_returns_permissions_and_users_count(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $role->givePermissionTo(['loans.view', 'loans.create']);
        User::factory()->create()->assignRole($role);
        User::factory()->create()->assignRole($role);
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson("/api/v1/roles/{$role->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.permissions'));
        $this->assertSame(2, $response->json('data.users_count'));
    }

    public function test_show_requires_authentication(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);

        $response = $this->getJson("/api/v1/roles/{$role->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_roles_view_permission(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/roles/{$role->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_role(): void
    {
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson('/api/v1/roles/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
