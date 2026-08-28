<?php

namespace Tests\Feature\Repayment;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentCreateTest extends TestCase
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

    private function scheduleWithOutstanding(float $totalAmount): RepaymentSchedule
    {
        $loan = Loan::factory()->active()->create();

        return RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'total_amount' => $totalAmount,
            'outstanding_amount' => $totalAmount,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RepaymentSchedule $schedule, array $overrides = []): array
    {
        return array_merge([
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => $schedule->total_amount,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], $overrides);
    }

    public function test_authorized_user_can_record_a_repayment(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201)->assertJson(['success' => true]);
        $this->assertDatabaseCount('repayments', 1);
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_repayment_creates_a_receipt_referencing_it(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule), ['Authorization' => "Bearer {$token}"]);

        $receiptId = $response->json('data.repayments.0.receipt_id');
        $this->assertNotNull($receiptId);
        $this->assertDatabaseHas('receipts', ['id' => $receiptId, 'amount' => '300000.00']);
        $this->assertSame($receiptId, $response->json('data.repayments.0.receipt.id'));
    }

    public function test_repayment_references_the_correct_loan_and_schedule(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule), ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($schedule->loan_id, $response->json('data.repayments.0.loan_id'));
        $this->assertSame($schedule->id, $response->json('data.repayments.0.repayment_schedule_id'));
    }

    public function test_store_requires_authentication(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule));

        $response->assertStatus(401);
    }

    public function test_store_requires_repayments_create_permission(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken([]);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_validation_requires_all_fields(): void
    {
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', [], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'loan_id', 'repayment_schedule_id', 'amount', 'repayment_date', 'payment_method',
        ]);
    }

    public function test_nonexistent_loan_id_fails_validation(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['loan_id' => 999999]), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['loan_id']);
    }

    public function test_nonexistent_repayment_schedule_id_fails_validation(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['repayment_schedule_id' => 999999]), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['repayment_schedule_id']);
    }

    public function test_invalid_payment_method_fails_validation(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['payment_method' => 'bitcoin']), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    }

    public function test_future_repayment_date_fails_validation(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson(
            '/api/v1/repayments',
            $this->payload($schedule, ['repayment_date' => now()->addDay()->toDateString()]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['repayment_date']);
    }

    public function test_cannot_repay_a_schedule_belonging_to_another_loan(): void
    {
        $otherLoan = Loan::factory()->active()->create();
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson(
            '/api/v1/repayments',
            $this->payload($schedule, ['loan_id' => $otherLoan->id]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'The selected repayment schedule does not belong to the selected loan.',
        ]);
        $this->assertDatabaseCount('repayments', 0);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_cannot_repay_more_than_the_outstanding_amount(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson(
            '/api/v1/repayments',
            $this->payload($schedule, ['amount' => 300000.01]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Repayment amount exceeds the outstanding balance for this installment and any subsequent unpaid installments on this loan.',
        ]);
        $this->assertDatabaseCount('repayments', 0);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_cannot_repay_an_already_fully_paid_schedule(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $this->postJson('/api/v1/repayments', $this->payload($schedule), ['Authorization' => "Bearer {$token}"])
            ->assertStatus(201);

        $response = $this->postJson(
            '/api/v1/repayments',
            $this->payload($schedule, ['amount' => 1]),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Repayment amount exceeds the outstanding balance for this installment and any subsequent unpaid installments on this loan.',
        ]);
        $this->assertDatabaseCount('repayments', 1);
    }

    public function test_cannot_repay_zero_amount(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['amount' => 0]), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_cannot_repay_negative_amount(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['amount' => -100]), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_cannot_repay_with_more_than_two_decimal_places(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', $this->payload($schedule, ['amount' => 100.123]), ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }
}
