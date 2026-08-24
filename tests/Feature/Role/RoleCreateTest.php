<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleCreateTest extends TestCase
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

    public function test_authorized_user_can_create_a_role(): void
    {
        $token = $this->actingUserToken(['roles.create']);

        $response = $this->postJson('/api/v1/roles', ['name' => 'loan_officer'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'loan_officer', 'guard_name' => 'api']);
    }

    public function test_guard_is_always_forced_to_api(): void
    {
        $token = $this->actingUserToken(['roles.create']);

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'loan_officer',
            'guard_name' => 'web',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertSame('api', $response->json('data.guard_name'));
        $this->assertDatabaseMissing('roles', ['name' => 'loan_officer', 'guard_name' => 'web']);
    }

    public function test_duplicate_role_name_is_rejected(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.create']);

        $response = $this->postJson('/api/v1/roles', ['name' => 'loan_officer'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/roles', ['name' => 'loan_officer']);

        $response->assertStatus(401);
    }

    public function test_store_requires_roles_create_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->postJson('/api/v1/roles', ['name' => 'loan_officer'], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }
}
