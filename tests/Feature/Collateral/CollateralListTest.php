<?php

namespace Tests\Feature\Collateral;

use App\Models\Collateral;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CollateralListTest extends TestCase
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

    public function test_authorized_user_can_list_a_customers_collateral(): void
    {
        $customer = Customer::factory()->create();
        Collateral::factory()->count(3)->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $response->json('data.collaterals'));
    }

    public function test_unauthenticated_request_cannot_list_collateral(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/collaterals");

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_view_permission_cannot_list_collateral(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_listing_collateral_only_returns_records_for_the_given_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        Collateral::factory()->create(['customer_id' => $customer->id, 'type' => 'Vehicle']);
        Collateral::factory()->create(['customer_id' => $otherCustomer->id, 'type' => 'Land']);
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.collaterals'));
        $this->assertSame('Vehicle', $response->json('data.collaterals.0.type'));
    }

    public function test_search_matches_type_and_description(): void
    {
        $customer = Customer::factory()->create();
        Collateral::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'Vehicle',
            'description' => 'Toyota Noah, registration number T 123 ABC',
        ]);
        Collateral::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'Land',
            'description' => 'Residential plot in Dar es Salaam',
        ]);
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals?search=Toyota",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.collaterals'));
        $this->assertSame('Vehicle', $response->json('data.collaterals.0.type'));
    }

    public function test_status_filter_returns_only_matching_records(): void
    {
        $customer = Customer::factory()->create();
        Collateral::factory()->create(['customer_id' => $customer->id, 'status' => 'active']);
        Collateral::factory()->inactive()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals?status=inactive",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.collaterals'));
        $this->assertSame('inactive', $response->json('data.collaterals.0.status'));
    }

    public function test_listing_collateral_is_paginated(): void
    {
        $customer = Customer::factory()->create();
        Collateral::factory()->count(5)->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/collaterals?page=1&per_page=2",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.collaterals'));
        $this->assertSame(5, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
    }

    public function test_listing_collateral_for_a_nonexistent_customer_returns_404(): void
    {
        $token = $this->actingUserToken(['collateral.view']);

        $response = $this->getJson(
            '/api/v1/customers/999999/collaterals',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }
}
