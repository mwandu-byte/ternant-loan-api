<?php

namespace Tests\Feature\Loan;

use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanShowTest extends TestCase
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

    public function test_authorized_user_can_view_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            "/api/v1/loans/{$loan->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['id' => $loan->id, 'customer_id' => $customer->id],
        ]);
        $this->assertSame($customer->id, $response->json('data.customer.id'));
    }

    public function test_show_includes_synced_collaterals(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->for($customer)->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $loan->collaterals()->sync([$collateral->id]);
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            "/api/v1/loans/{$loan->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame([$collateral->id], array_column($response->json('data.collaterals'), 'id'));
    }

    public function test_unauthenticated_request_cannot_view_a_loan(): void
    {
        $loan = Loan::factory()->create();

        $response = $this->getJson("/api/v1/loans/{$loan->id}");

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_view_permission_cannot_view_a_loan(): void
    {
        $loan = Loan::factory()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson(
            "/api/v1/loans/{$loan->id}",
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_viewing_a_nonexistent_loan_returns_404_with_loan_not_found_message(): void
    {
        $token = $this->actingUserToken(['loans.view']);

        $response = $this->getJson(
            '/api/v1/loans/999999',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Loan not found.',
        ]);
    }
}
