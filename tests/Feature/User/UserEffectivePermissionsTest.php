<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserEffectivePermissionsTest extends TestCase
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

    public function test_returns_permissions_inherited_from_roles(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $role->givePermissionTo(['loans.view', 'loans.create']);

        $target = User::factory()->create();
        $target->assignRole($role);
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson("/api/v1/users/{$target->id}/permissions", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(['loans.view', 'loans.create'], $response->json('data.permissions'));
    }

    public function test_returns_permissions_granted_directly_to_the_user(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        $target = User::factory()->create();
        $target->givePermissionTo('loans.view');
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson("/api/v1/users/{$target->id}/permissions", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertContains('loans.view', $response->json('data.permissions'));
    }

    public function test_requires_users_view_permission(): void
    {
        $target = User::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/users/{$target->id}/permissions", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_returns_404_for_nonexistent_user(): void
    {
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users/999999/permissions', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }
}
