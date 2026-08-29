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

class CollateralDeleteTest extends TestCase
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

    public function test_authorized_user_can_delete_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.delete']);

        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Collateral deleted successfully',
            'data' => null,
        ]);
        $this->assertDatabaseMissing('collaterals', ['id' => $collateral->id]);
    }

    public function test_unauthenticated_request_cannot_delete_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}");

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_delete_permission_cannot_delete_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
        $this->assertDatabaseHas('collaterals', ['id' => $collateral->id]);
    }

    public function test_deleting_a_nonexistent_collateral_returns_404_with_collateral_not_found_message(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.delete']);

        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/collaterals/999999",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Collateral not found.',
        ]);
    }

    public function test_deleting_collateral_belonging_to_a_different_customer_returns_404(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $otherCustomer->id]);
        $token = $this->actingUserToken(['collateral.delete']);

        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Collateral not found.',
        ]);
        $this->assertDatabaseHas('collaterals', ['id' => $collateral->id]);
    }

    public function test_deleting_a_customer_with_existing_collateral_is_rejected(): void
    {
        $customer = Customer::factory()->create();
        Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['customers.delete']);

        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }
}
