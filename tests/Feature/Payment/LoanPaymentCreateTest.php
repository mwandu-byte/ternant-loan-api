<?php

namespace Tests\Feature\Payment;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoanPaymentCreateTest extends TestCase
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

    private function activeLoan(array $overrides = []): Loan
    {
        return Loan::factory()->active()->create(array_merge([
            'principal_amount' => 1200000,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 1200000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'reference_no' => 'TXN-001',
        ], $overrides);
    }

    public function test_authorized_user_can_disburse_an_eligible_loan(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_payment_is_linked_to_the_correct_loan(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($loan->id, $response->json('data.loan_id'));
        $this->assertDatabaseHas('payments', ['loan_id' => $loan->id]);
    }

    public function test_payment_amount_is_stored_accurately(): void
    {
        $loan = $this->activeLoan(['principal_amount' => 1234.56]);
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/payments",
            $this->payload(['amount' => 1234.56]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(201);
        $this->assertSame('1234.56', $response->json('data.amount'));
    }

    public function test_store_requires_authentication(): void
    {
        $loan = $this->activeLoan();

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload());

        $response->assertStatus(401);
    }

    public function test_store_requires_payments_create_permission(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken([]);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_disbursement_amount_must_equal_the_loans_principal_amount(): void
    {
        $loan = $this->activeLoan(['principal_amount' => 1200000]);
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson(
            "/api/v1/loans/{$loan->id}/payments",
            $this->payload(['amount' => 999999]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => "Disbursement amount must equal the loan's principal amount.",
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pending_loan_is_not_eligible_for_disbursement(): void
    {
        $loan = Loan::factory()->create(['status' => 'pending', 'principal_amount' => 1200000]);
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Only active loans are eligible for disbursement.',
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_completed_loan_is_not_eligible_for_disbursement(): void
    {
        $loan = Loan::factory()->completed()->create(['principal_amount' => 1200000]);
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_duplicate_disbursement_is_rejected(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['payments.create']);

        $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'This loan has already been disbursed.',
        ]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_disbursement_never_creates_a_repayment_or_receipt(): void
    {
        $loan = $this->activeLoan();
        $token = $this->actingUserToken(['payments.create']);

        $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);

        $this->assertDatabaseCount('repayments', 0);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_disbursement_never_modifies_repayment_schedules(): void
    {
        $loan = $this->activeLoan();
        $schedule = $loan->repaymentSchedules()->create([
            'installment_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 1200000,
            'interest_amount' => 0,
            'total_amount' => 1200000,
            'outstanding_amount' => 1200000,
            'status' => 'pending',
        ]);
        $token = $this->actingUserToken(['payments.create']);

        $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);

        $schedule->refresh();
        $this->assertSame('1200000.00', $schedule->outstanding_amount);
        $this->assertSame('pending', $schedule->status);
    }

    public function test_disbursement_returns_404_for_nonexistent_loan(): void
    {
        $token = $this->actingUserToken(['payments.create']);

        $response = $this->postJson('/api/v1/loans/999999/payments', $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }

    public function test_paid_by_is_the_authenticated_user(): void
    {
        $loan = $this->activeLoan();
        Permission::firstOrCreate(['name' => 'payments.create', 'guard_name' => 'api']);
        $role = Role::create(['name' => 'test-role-'.uniqid(), 'guard_name' => 'api']);
        $role->givePermissionTo(['payments.create']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $token = JWTAuth::fromUser($user);

        $response = $this->postJson("/api/v1/loans/{$loan->id}/payments", $this->payload(), ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($user->id, $response->json('data.paid_by'));
    }
}
