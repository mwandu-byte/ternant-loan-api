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

class CollateralUpdateTest extends TestCase
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

    public function test_authorized_user_can_update_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id, 'type' => 'Vehicle']);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['type' => 'Land', 'status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['type' => 'Land', 'status' => 'inactive'],
        ]);
        $this->assertDatabaseHas('collaterals', ['id' => $collateral->id, 'type' => 'Land', 'status' => 'inactive']);
    }

    public function test_unauthenticated_request_cannot_update_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['type' => 'Land'],
        );

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_update_permission_cannot_update_collateral(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['type' => 'Land'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_update_supports_partial_payloads(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'Vehicle',
            'description' => 'Toyota Noah, registration number T 123 ABC',
            'estimated_value' => 25000000,
        ]);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['status' => 'inactive'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('collaterals', [
            'id' => $collateral->id,
            'type' => 'Vehicle',
            'description' => 'Toyota Noah, registration number T 123 ABC',
            'status' => 'inactive',
        ]);
    }

    public function test_updating_collateral_rejects_an_invalid_status(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['status' => 'seized'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_updating_collateral_rejects_a_non_positive_estimated_value(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['estimated_value' => 0],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['estimated_value']);
    }

    public function test_customer_id_cannot_be_changed_through_update(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['type' => 'Land', 'customer_id' => $otherCustomer->id],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('collaterals', ['id' => $collateral->id, 'customer_id' => $customer->id]);
    }

    public function test_updating_a_nonexistent_collateral_returns_404_with_collateral_not_found_message(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/999999",
            ['type' => 'Land'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Collateral not found.',
        ]);
    }

    public function test_updating_collateral_belonging_to_a_different_customer_returns_404(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $collateral = Collateral::factory()->create(['customer_id' => $otherCustomer->id]);
        $token = $this->actingUserToken(['collateral.update']);

        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/collaterals/{$collateral->id}",
            ['type' => 'Land'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Collateral not found.',
        ]);
    }
}
