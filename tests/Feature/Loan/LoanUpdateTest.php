<?php

namespace Tests\Feature\Loan;

use App\Models\Collateral;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentSchedule;
use App\Models\RepaymentTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedDefaultLoanConfiguration();
    }

    /**
     * Seed the repayment frequency and repayment terms this test file's
     * update payloads are validated against.
     */
    private function seedDefaultLoanConfiguration(): void
    {
        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);

        foreach ([3, 6, 12] as $months) {
            RepaymentTerm::factory()->create([
                'name' => "{$months} Months", 'value' => $months, 'unit' => 'months', 'status' => 'active',
            ]);
        }
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

    public function test_pending_loan_can_update_repayment_schedule_and_due_date_is_recalculated(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'start_date' => '2026-01-01',
            'repayment_term' => 12,
        ]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['start_date' => '2026-02-01', 'repayment_term' => 3],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('2026-02-01', $response->json('data.start_date'));
        $this->assertSame('2026-05-01', $response->json('data.due_date'));
    }

    public function test_pending_loan_can_update_collateral_ids(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->for($customer)->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['collateral_ids' => [$collateral->id]],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame([$collateral->id], array_column($response->json('data.collaterals'), 'id'));
    }

    public function test_pending_loan_can_transition_to_active(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'active'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('active', $response->json('data.status'));
    }

    public function test_pending_loan_can_transition_to_cancelled(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'cancelled'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('cancelled', $response->json('data.status'));
    }

    public function test_pending_loan_cannot_transition_directly_to_completed(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id, 'status' => 'pending']);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'completed'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_active_loan_can_update_notes(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['notes' => 'Customer requested a reminder call.'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('Customer requested a reminder call.', $response->json('data.notes'));
    }

    public function test_active_loan_can_transition_to_completed(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        RepaymentSchedule::factory()->paid()->create(['loan_id' => $loan->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'completed'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('completed', $response->json('data.status'));
    }

    public function test_active_loan_with_outstanding_balance_cannot_be_marked_completed(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'total_amount' => 610000,
            'outstanding_amount' => 10000,
            'status' => 'partially_paid',
        ]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'completed'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
        $this->assertSame('active', $loan->fresh()->status);
    }

    public function test_active_loan_with_no_schedules_cannot_be_marked_completed(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'completed'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
        $this->assertSame('active', $loan->fresh()->status);
    }

    public function test_active_loan_cannot_transition_to_cancelled(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['status' => 'cancelled'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'active']);
    }

    public function test_active_loan_rejects_changes_to_repayment_frequency(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['repayment_frequency' => 'monthly'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['repayment_frequency']);
    }

    public function test_active_loan_rejects_changes_to_repayment_term(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['repayment_term' => 6],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['repayment_term']);
    }

    public function test_active_loan_rejects_changes_to_start_date(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['start_date' => '2026-03-01'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['start_date']);
    }

    public function test_active_loan_rejects_changes_to_collateral_ids(): void
    {
        $customer = Customer::factory()->create();
        $collateral = Collateral::factory()->for($customer)->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['collateral_ids' => [$collateral->id]],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['collateral_ids']);
    }

    public function test_completed_loan_cannot_be_updated_at_all(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->completed()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['notes' => 'Attempting a change.'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan can no longer be modified.',
        ]);
    }

    public function test_cancelled_loan_cannot_be_updated_at_all(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->cancelled()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['notes' => 'Attempting a change.'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan can no longer be modified.',
        ]);
    }

    public function test_update_rejects_collateral_ids_belonging_to_another_customer(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $foreignCollateral = Collateral::factory()->for($otherCustomer)->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['collateral_ids' => [$foreignCollateral->id]],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['collateral_ids']);
    }

    public function test_financial_fields_cannot_be_changed_via_update(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create([
            'customer_id' => $customer->id,
            'principal_amount' => 1000000,
            'interest_rate' => 22,
        ]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            [
                'principal_amount' => 5000000,
                'interest_rate' => 5,
                'interest_amount' => 5,
                'total_amount' => 5,
                'reference_no' => 'LN-9999-999999',
                'notes' => 'unchanged financials',
            ],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('1000000.00', $response->json('data.principal_amount'));
        $this->assertSame('22.00', $response->json('data.interest_rate'));
        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'principal_amount' => 1000000,
            'reference_no' => $loan->reference_no,
        ]);
    }

    public function test_unauthenticated_request_cannot_update_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['notes' => 'x'],
        );

        $response->assertStatus(401)->assertJson([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
    }

    public function test_user_without_update_permission_cannot_update_a_loan(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken([]);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['notes' => 'x'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_updating_a_nonexistent_loan_returns_404(): void
    {
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            '/api/v1/loans/999999',
            ['notes' => 'x'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(404)->assertJson([
            'success' => false,
            'message' => 'Loan not found.',
        ]);
    }

    public function test_customer_id_cannot_be_changed_via_update(): void
    {
        $customer = Customer::factory()->create();
        $otherCustomer = Customer::factory()->create();
        $loan = Loan::factory()->create(['customer_id' => $customer->id]);
        $token = $this->actingUserToken(['loans.update']);

        $response = $this->putJson(
            "/api/v1/loans/{$loan->id}",
            ['customer_id' => $otherCustomer->id, 'notes' => 'x'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame($customer->id, $response->json('data.customer_id'));
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'customer_id' => $customer->id]);
    }
}
