<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleListTest extends TestCase
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

    public function test_authorized_user_can_list_roles(): void
    {
        Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        Role::create(['name' => 'auditor', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson('/api/v1/roles', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        // 2 created + the test-role assigned to the acting user.
        $this->assertCount(3, $response->json('data.roles'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(401);
    }

    public function test_list_requires_roles_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/roles', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_list_is_paginated(): void
    {
        Role::create(['name' => 'role-a', 'guard_name' => 'api']);
        Role::create(['name' => 'role-b', 'guard_name' => 'api']);
        Role::create(['name' => 'role-c', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['roles.view']);

        $response = $this->getJson('/api/v1/roles?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.roles'));
        $this->assertSame(4, $response->json('data.pagination.total'));
    }
}
