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

class RepaymentDuplicateReferenceTest extends TestCase
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

    public function test_duplicate_reference_no_is_rejected_and_the_first_repayment_is_unaffected(): void
    {
        $token = $this->actingUserToken(['repayments.create']);

        $firstSchedule = $this->scheduleWithOutstanding(100000);
        $secondSchedule = $this->scheduleWithOutstanding(100000);

        $first = $this->postJson('/api/v1/repayments', [
            'loan_id' => $firstSchedule->loan_id,
            'repayment_schedule_id' => $firstSchedule->id,
            'amount' => 100000,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'mobile_money',
            'reference_no' => 'MPESA-DUPLICATE-1',
        ], ['Authorization' => "Bearer {$token}"]);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/repayments', [
            'loan_id' => $secondSchedule->loan_id,
            'repayment_schedule_id' => $secondSchedule->id,
            'amount' => 100000,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'mobile_money',
            'reference_no' => 'MPESA-DUPLICATE-1',
        ], ['Authorization' => "Bearer {$token}"]);

        $second->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'A receipt already exists with this reference number.',
        ]);

        // The rejected attempt must not leave any orphan rows — no receipt
        // and no repayment for the second schedule, and the first
        // transaction's records must be untouched.
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('repayments', 1);
        $this->assertDatabaseHas('repayments', ['id' => $first->json('data.repayments.0.id')]);
        $this->assertSame('pending', $secondSchedule->fresh()->status);
        $this->assertSame('100000.00', $secondSchedule->fresh()->outstanding_amount);
    }

    public function test_amount_and_date_alone_do_not_prevent_two_genuinely_separate_repayments(): void
    {
        $token = $this->actingUserToken(['repayments.create']);

        $firstSchedule = $this->scheduleWithOutstanding(50000);
        $secondSchedule = $this->scheduleWithOutstanding(50000);
        $date = now()->toDateString();

        $this->postJson('/api/v1/repayments', [
            'loan_id' => $firstSchedule->loan_id,
            'repayment_schedule_id' => $firstSchedule->id,
            'amount' => 50000,
            'repayment_date' => $date,
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        // Same amount, same date, no reference_no supplied — this is a
        // legitimately different transaction (different schedule) and
        // must not be blocked by an amount+date heuristic.
        $this->postJson('/api/v1/repayments', [
            'loan_id' => $secondSchedule->loan_id,
            'repayment_schedule_id' => $secondSchedule->id,
            'amount' => 50000,
            'repayment_date' => $date,
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        $this->assertDatabaseCount('repayments', 2);
        $this->assertDatabaseCount('receipts', 2);
    }
}
