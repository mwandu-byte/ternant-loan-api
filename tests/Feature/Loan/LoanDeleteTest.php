<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanDeleteTest extends TestCase
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

    public function test_pending_loan_is_hard_deleted(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $token = $this->actingUserToken(['loans.delete']);

        $response = $this->deleteJson(
            "/api/v1/loans/{$loan->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Loan deleted successfully',
            'data' => null,
        ]);
        $this->assertDatabaseMissing('loans', ['id' => $loan->id]);
    }

    public function test_active_loan_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.delete']);

        $response = $this->deleteJson(
            "/api/v1/loans/{$loan->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan can no longer be modified.',
        ]);
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'active']);
    }

    public function test_completed_loan_cannot_be_deleted(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->completed()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.delete']);

        $response = $this->deleteJson(
            "/api/v1/loans/{$loan->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan can no longer be modified.',
        ]);
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'completed']);
    }

    public function test_already_cancelled_loan_cannot_be_deleted_again(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->cancelled()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.delete']);

        $response = $this->deleteJson(
            "/api/v1/loans/{$loan->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan can no longer be modified.',
        ]);
    }

    public function test_unauthenticated_request_cannot_delete_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);

        $response = $this->deleteJson("/api/v1/loans/{$loan->id}");

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_delete_permission_cannot_delete_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $token = $this->actingUserToken([]);

        $response = $this->deleteJson(
            "/api/v1/loans/{$loan->id}",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
        $this->assertDatabaseHas('loans', ['id' => $loan->id]);
    }

    public function test_deleting_a_nonexistent_loan_returns_404(): void
    {
        $token = $this->actingUserToken(['loans.delete']);

        $response = $this->deleteJson(
            '/api/v1/loans/999999',
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Loan not found.',
        ]);
    }
}
