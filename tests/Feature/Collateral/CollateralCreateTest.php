<?php

namespace Tests\Feature\Collateral;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CollateralCreateTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'Vehicle',
            'description' => 'Toyota Noah, registration number T 123 ABC',
            'estimated_value' => 25000000,
            'status' => 'active',
        ], $overrides);
    }

    public function test_authorized_user_can_create_collateral_for_a_customer(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertSame($customer->id, $response->json('data.customer_id'));
        $this->assertDatabaseHas('collaterals', [
            'customer_id' => $customer->id,
            'type' => 'Vehicle',
        ]);
    }

    public function test_unauthenticated_request_cannot_create_collateral(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(),
        );

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_create_permission_cannot_create_collateral(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_creating_collateral_requires_type(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(['type' => '']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_creating_collateral_requires_description(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(['description' => '']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_creating_collateral_requires_estimated_value(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(['estimated_value' => '']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['estimated_value']);
    }

    public function test_estimated_value_must_be_greater_than_zero(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(['estimated_value' => 0]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['estimated_value']);
    }

    public function test_creating_collateral_rejects_an_invalid_status(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(['status' => 'pledged']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_customer_id_in_payload_is_ignored_and_taken_from_the_route(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            [...$this->validPayload(), 'customer_id' => $otherCustomer->id],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame($customer->id, $response->json('data.customer_id'));
    }

    public function test_creating_collateral_for_a_nonexistent_customer_returns_404(): void
    {
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            '/api/v1/customers/999999/collaterals',
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404);
    }

    public function test_collateral_response_does_not_expose_unrelated_information(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['collateral.create']);

        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/collaterals",
            $this->validPayload(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertEqualsCanonicalizing([
            'id', 'customer_id', 'type', 'description', 'estimated_value', 'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
    }
}
