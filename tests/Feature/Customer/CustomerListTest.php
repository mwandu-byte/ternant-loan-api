<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerListTest extends TestCase
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

    public function test_authorized_user_can_list_customers(): void
    {
        Customer::factory()->count(3)->create();
        $token = $this->actingUserToken(['customers.view']);

        $response = $this->getJson('/api/v1/customers', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.customers'));
    }

    public function test_unauthenticated_user_cannot_list_customers(): void
    {
        $response = $this->getJson('/api/v1/customers');

        $response->assertStatus(401)->assertJson(['success' => false, 'message' => 'Unauthenticated']);
    }

    public function test_user_without_view_permission_cannot_list_customers(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/customers', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_customers_can_be_searched_by_full_name_phone_or_identification_number(): void
    {
        Customer::factory()->create(['full_name' => 'John Doe']);
        Customer::factory()->create(['full_name' => 'Jane Smith', 'phone' => '+255700000099']);
        Customer::factory()->create(['full_name' => 'Someone Else', 'identification_number' => 'ZZZ999']);
        $token = $this->actingUserToken(['customers.view']);

        $response = $this->getJson('/api/v1/customers?search=John', ['Authorization' => "Bearer {$token}"]);
        $response->assertOk();
        $this->assertCount(1, $response->json('data.customers'));
        $this->assertSame('John Doe', $response->json('data.customers.0.full_name'));

        $response = $this->getJson('/api/v1/customers?search=700000099', ['Authorization' => "Bearer {$token}"]);
        $this->assertCount(1, $response->json('data.customers'));

        $response = $this->getJson('/api/v1/customers?search=ZZZ999', ['Authorization' => "Bearer {$token}"]);
        $this->assertCount(1, $response->json('data.customers'));
    }

    public function test_customers_can_be_filtered_by_status(): void
    {
        Customer::factory()->count(2)->create(['status' => 'active']);
        Customer::factory()->inactive()->create();
        $token = $this->actingUserToken(['customers.view']);

        $response = $this->getJson('/api/v1/customers?status=inactive', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.customers'));
        $this->assertSame('inactive', $response->json('data.customers.0.status'));
    }

    public function test_customer_list_is_paginated(): void
    {
        Customer::factory()->count(25)->create();
        $token = $this->actingUserToken(['customers.view']);

        $response = $this->getJson('/api/v1/customers?per_page=10&page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertCount(10, $response->json('data.customers'));
        $this->assertSame(2, $response->json('data.pagination.current_page'));
        $this->assertSame(10, $response->json('data.pagination.per_page'));
        $this->assertSame(25, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
    }
}
