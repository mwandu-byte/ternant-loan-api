<?php

namespace Tests\Feature\Loan;

use App\Models\Customer;
use App\Models\InterestRule;
use App\Models\Loan;
use App\Models\RepaymentFrequency;
use App\Models\RepaymentTerm;
use App\Models\User;
use App\Services\Loan\LoanService;
use App\Services\Repayment\RepaymentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanRepaymentScheduleAutoGenerationTest extends TestCase
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
            'minimum_amount' => 0, 'maximum_amount' => 499999.99, 'interest_rate' => 30.00, 'status' => 'active',
        ]);
        InterestRule::factory()->create([
            'minimum_amount' => 500000, 'maximum_amount' => 4000000, 'interest_rate' => 22.00, 'status' => 'active',
        ]);

        RepaymentFrequency::factory()->create([
            'name' => 'Monthly', 'code' => 'monthly', 'interval_value' => 1, 'interval_unit' => 'month', 'status' => 'active',
        ]);
        RepaymentFrequency::factory()->create([
            'name' => 'Weekly', 'code' => 'weekly', 'interval_value' => 1, 'interval_unit' => 'week', 'status' => 'active',
        ]);

        foreach ([4, 12] as $months) {
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

    // -----------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------

    public function test_creating_an_active_loan_automatically_generates_a_repayment_schedule(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('active', $response->json('data.status'));
        $this->assertCount(4, $response->json('data.repayment_schedules'));
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_creating_a_pending_loan_does_not_generate_a_repayment_schedule(): void
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
        $this->assertSame([], $response->json('data.repayment_schedules'));
        $this->assertDatabaseCount('repayment_schedules', 0);
    }

    // StoreLoanRequest only allows `pending`/`active` at creation time
    // (completed/cancelled loans cannot be created directly), so
    // `pending` is the only non-active creation path there is to
    // verify — covered by the test above.

    public function test_schedule_generation_failure_rolls_back_active_loan_creation(): void
    {
        $this->mock(RepaymentScheduleService::class, function ($mock) {
            $mock->shouldReceive('generateForLoan')->once()->andThrow(new RuntimeException('boom'));
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

    // -----------------------------------------------------------------
    // Activation via update
    // -----------------------------------------------------------------

    public function test_changing_a_pending_loan_to_active_automatically_generates_the_schedule(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.update']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $this->assertDatabaseCount('repayment_schedules', 0);

        $response = $this->putJson(
            "/api/v1/loans/{$loanId}",
            ['status' => 'active'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Loan activated successfully',
        ]);
        $this->assertSame('active', $response->json('data.status'));
        $this->assertCount(4, $response->json('data.repayment_schedules'));
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_updating_an_already_active_loan_does_not_generate_a_duplicate_schedule(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.update']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $this->assertDatabaseCount('repayment_schedules', 4);

        $response = $this->putJson(
            "/api/v1/loans/{$loanId}",
            ['status' => 'active', 'notes' => 'unrelated update'],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Loan updated successfully',
        ]);
        $this->assertCount(4, $response->json('data.repayment_schedules'));
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_updating_notes_on_an_active_loan_does_not_generate_a_schedule_twice(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'loans.update']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $this->putJson(
            "/api/v1/loans/{$loanId}",
            ['notes' => 'just a note'],
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(200);

        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    public function test_schedule_generation_failure_rolls_back_status_activation(): void
    {
        $loan = Loan::factory()->create([
            'status' => 'pending',
            'repayment_frequency' => 'monthly',
            'repayment_term' => 4,
        ]);

        $this->mock(RepaymentScheduleService::class, function ($mock) {
            $mock->shouldReceive('generateForLoan')->once()->andThrow(new RuntimeException('boom'));
        });

        $service = app(LoanService::class);

        try {
            $service->update($loan, ['status' => 'active']);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('pending', $loan->fresh()->status);
        $this->assertDatabaseCount('repayment_schedules', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    // -----------------------------------------------------------------
    // Duplicate protection across the manual recovery endpoint
    // -----------------------------------------------------------------

    public function test_manual_generate_endpoint_rejects_a_loan_that_was_auto_generated_on_activation(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create', 'repayment-schedules.create']);

        $loanId = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->json('data.id');

        $response = $this->postJson(
            "/api/v1/loans/{$loanId}/repayment-schedules/generate",
            [],
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'A repayment schedule has already been generated for this loan.',
        ]);
        $this->assertDatabaseCount('repayment_schedules', 4);
    }

    // -----------------------------------------------------------------
    // Correctness of the auto-generated schedule
    // -----------------------------------------------------------------

    public function test_auto_generated_schedule_uses_the_loans_stored_interest_and_total_amounts(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active', 'principal_amount' => 1200000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $interestAmount = $response->json('data.interest_amount');
        $totalAmount = $response->json('data.total_amount');
        $schedule = $response->json('data.repayment_schedules');

        $this->assertSame(
            $interestAmount,
            number_format(array_sum(array_column($schedule, 'interest_amount')), 2, '.', ''),
        );
        $this->assertSame(
            $totalAmount,
            number_format(array_sum(array_column($schedule, 'total_amount')), 2, '.', ''),
        );
    }

    public function test_discounted_loans_generate_schedules_using_the_applied_discounted_values_not_the_configured_rate(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, [
                'status' => 'active',
                'principal_amount' => 1000000,
                'has_discount' => true,
                'discount_rate' => 20,
            ]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('20.00', $response->json('data.applied_interest_rate'));
        // Configured rate for this principal band is 22%; the schedule
        // must reflect the 20% discounted amount actually stored on the
        // loan, not a re-derived 22% figure.
        $this->assertSame('200000.00', $response->json('data.interest_amount'));

        $schedule = $response->json('data.repayment_schedules');
        $this->assertSame(
            '200000.00',
            number_format(array_sum(array_column($schedule, 'interest_amount')), 2, '.', ''),
        );
        $this->assertSame(
            '1200000.00',
            number_format(array_sum(array_column($schedule, 'total_amount')), 2, '.', ''),
        );
    }

    public function test_auto_generated_schedule_respects_the_loans_stored_repayment_frequency(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, [
                'status' => 'active',
                'repayment_frequency' => 'weekly',
                'start_date' => '2026-01-05',
            ]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $dueDates = array_column($response->json('data.repayment_schedules'), 'due_date');
        $this->assertSame(['2026-01-12', '2026-01-19', '2026-01-26', '2026-02-02'], $dueDates);
    }

    public function test_auto_generated_schedule_respects_the_loans_stored_repayment_term(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active', 'repayment_term' => 12]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertCount(12, $response->json('data.repayment_schedules'));
    }

    public function test_principal_installment_totals_equal_loan_principal(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $response = $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active', 'principal_amount' => 1200000]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $schedule = $response->json('data.repayment_schedules');

        $this->assertSame(
            $response->json('data.principal_amount'),
            number_format(array_sum(array_column($schedule, 'principal_amount')), 2, '.', ''),
        );
    }

    public function test_no_penalty_records_are_created_by_automatic_generation(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->actingUserToken(['loans.create']);

        $this->postJson(
            '/api/v1/loans',
            $this->validPayload($customer->id, ['status' => 'active']),
            ['Authorization' => "Bearer {$token}"],
        )->assertStatus(201);

        // Activating a loan does disburse it automatically (see
        // LoanAutoDisbursementTest) — penalty accrual is a separate,
        // grace-period-gated process (see the Penalty test suite) and
        // must never be triggered merely by creating/activating a loan.
        $this->assertDatabaseCount('penalties', 0);
    }
}
