<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'reports.export', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.view']);

        $response = $this->getJson("/api/v1/permissions/{$permission->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $permission->id, 'name' => 'reports.export', 'guard_name' => 'api'],
        ]);
    }

    public function test_show_response_contains_exact_expected_fields(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'reports.export', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.view']);

        $response = $this->getJson("/api/v1/permissions/{$permission->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'guard_name', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
    }

    public function test_show_requires_authentication(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'reports.export', 'guard_name' => 'api']);

        $response = $this->getJson("/api/v1/permissions/{$permission->id}");

        $response->assertStatus(401);
    }

    public function test_show_requires_permissions_view_permission(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'reports.export', 'guard_name' => 'api']);
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/permissions/{$permission->id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_show_returns_404_for_nonexistent_permission(): void
    {
        $token = $this->actingUserToken(['permissions.view']);

        $response = $this->getJson('/api/v1/permissions/999999', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
