<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\InterestRule;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use App\Services\Loan\LoanService;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanAutoDisbursementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedDefaultLoanConfiguration();
    }

    private function seedDefaultLoanConfiguration(): void
    {
        InterestRule::factory()->create([
            'minimum_amount' => 0, 'maximum_amount' => 4000000, 'interest_rate' => 22.00, 'status' => 'active',
        ]);
        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);
        RepaymentTerm::factory()->create([
            'name' => '4 Months', 'value' => 4, 'unit' => 'months', 'status' => 'active',
        ]);
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
    private function validPayload(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $customerId,
            'principal_amount' => 1200000,
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
            'start_date' => '2026-01-24',
        ], $overrides);
    }

    public function test_creating_an_active_loan_automatically_disburses_it(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'loan_id' => $response->json('data.id'),
            'amount' => '1200000.00',
            'payment_method' => 'cash',
        ]);
    }

    public function test_creating_a_pending_loan_does_not_disburse_it(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_disbursement_amount_always_equals_the_loans_principal_amount(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active', 'principal_amount' => 875000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'loan_id' => $response->json('data.id'),
            'amount' => '875000.00',
        ]);
    }

    public function test_payment_method_can_be_supplied_on_the_loan_request(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, [
                'status' => 'active',
                'payment_method' => 'bank_transfer',
                'payment_reference_no' => 'TXN-DISB-001',
            ]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'loan_id' => $response->json('data.id'),
            'payment_method' => 'bank_transfer',
            'reference_no' => 'TXN-DISB-001',
        ]);
    }

    public function test_invalid_payment_method_on_loan_request_fails_validation(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active', 'payment_method' => 'bitcoin']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    }

    public function test_paid_by_is_the_authenticated_user_who_activated_the_loan(): void
    {
        $customer = Customer::factory()->create();
        Permission::firstOrCreate(['name' => 'loans.create', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['loans.create']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $token = JWTAuth::fromUser($user);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'loan_id' => $response->json('data.id'),
            'paid_by' => $user->id,
        ]);
    }

    public function test_activating_a_pending_loan_via_update_automatically_disburses_it(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.update']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $this->assertDatabaseCount('payments', 0);

        $response = $this->putJson(
            "/api/v1/loans/{$loanId}",
            ['status' => 'active'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['loan_id' => $loanId, 'amount' => '1200000.00']);
    }

    public function test_updating_an_already_active_loan_does_not_create_a_duplicate_disbursement(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.update']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $this->assertDatabaseCount('payments', 1);

        $this->putJson(
            "/api/v1/loans/{$loanId}",
            ['status' => 'active', 'notes' => 'unrelated update'],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(200);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_manual_disbursement_endpoint_rejects_a_loan_that_was_auto_disbursed_on_activation(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'payments.create']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $response = $this->postJson("/api/v1/loans/{$loanId}/payments", [
            'amount' => 1200000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan has already been disbursed.',
        ]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_disbursement_failure_rolls_back_active_loan_creation_entirely(): void
    {
        $this->mock(PaymentService::class, function ($mock) {
            $mock->shouldReceive('disburse')->once()->andThrow(new RuntimeException('boom'));
        });

        $customer = Customer::factory()->create();
        $service = app(LoanService::class);

        try {
            $service->create($this->validPayload($customer->id, ['status' => 'active']));
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertDatabaseCount('loans', 0);
        $this->assertDatabaseCount('repayment_schedules', 0);
        $this->assertDatabaseCount('payments', 0);
    }
}
