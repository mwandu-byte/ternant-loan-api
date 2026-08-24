<?php

namespace Tests\Feature\Permission;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionListTest extends TestCase
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

    public function test_authorized_user_can_list_permissions(): void
    {
        Permission::firstOrCreate(['name' => 'loans.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.view']);

        $response = $this->getJson('/api/v1/permissions', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        // 2 created + permissions.view itself.
        $this->assertCount(3, $response->json('data.permissions'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/permissions');

        $response->assertStatus(401);
    }

    public function test_list_requires_permissions_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/permissions', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_list_is_paginated(): void
    {
        Permission::firstOrCreate(['name' => 'a.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'b.view', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'c.view', 'guard_name' => 'api']);
        $token = $this->actingUserToken(['permissions.view']);

        $response = $this->getJson('/api/v1/permissions?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.permissions'));
        $this->assertSame(4, $response->json('data.pagination.total'));
    }
}
