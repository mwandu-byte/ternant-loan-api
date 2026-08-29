<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserListTest extends TestCase
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

    public function test_authorized_user_can_list_users(): void
    {
        User::factory()->count(3)->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        // 3 created + the authenticated user itself.
        $this->assertCount(4, $response->json('data.users'));
    }

    public function test_list_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertStatus(401);
    }

    public function test_list_requires_users_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/users', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_list_can_search_by_name(): void
    {
        User::factory()->create(['name' => 'Alice Johnson']);
        User::factory()->create(['name' => 'Bob Smith']);
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users?search=Alice', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $names = collect($response->json('data.users'))->pluck('name');
        $this->assertTrue($names->contains('Alice Johnson'));
        $this->assertFalse($names->contains('Bob Smith'));
    }

    public function test_list_can_search_by_email(): void
    {
        User::factory()->create(['email' => 'target-user@example.com']);
        User::factory()->create(['email' => 'someone-else@example.com']);
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users?search=target-user', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $emails = collect($response->json('data.users'))->pluck('email');
        $this->assertTrue($emails->contains('target-user@example.com'));
        $this->assertFalse($emails->contains('someone-else@example.com'));
    }

    public function test_list_can_filter_by_status(): void
    {
        User::factory()->create(['is_enabled' => true]);
        User::factory()->create(['is_enabled' => false]);
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users?status=inactive', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $statuses = collect($response->json('data.users'))->pluck('status')->unique();
        $this->assertEqualsCanonicalizing(['inactive'], $statuses->all());
    }

    public function test_list_can_filter_by_role(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $withRole = User::factory()->create();
        $withRole->assignRole($role);
        User::factory()->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users?role=loan_officer', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $ids = collect($response->json('data.users'))->pluck('id');
        $this->assertTrue($ids->contains($withRole->id));
    }

    public function test_list_response_includes_assigned_roles(): void
    {
        $role = Role::create(['name' => 'loan_officer', 'guard_name' => 'api']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $entry = collect($response->json('data.users'))->firstWhere('id', $user->id);
        $this->assertSame('loan_officer', $entry['roles'][0]['name']);
    }

    public function test_list_is_paginated(): void
    {
        User::factory()->count(4)->create();
        $token = $this->actingUserToken(['users.view']);

        $response = $this->getJson('/api/v1/users?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.users'));
        $this->assertSame(5, $response->json('data.pagination.total'));
    }
}
