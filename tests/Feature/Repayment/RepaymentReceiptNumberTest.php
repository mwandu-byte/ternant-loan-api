<?php

namespace Tests\Feature\Repayment;

use App\Models\Loan;
use App\Models\Receipt;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentReceiptNumberTest extends TestCase
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

    private function repay(RepaymentSchedule $schedule, string $token): string
    {
        $response = $this->postJson('/api/v1/repayments', [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => $schedule->total_amount,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        return $response->json('data.receipt.receipt_no');
    }

    public function test_receipt_numbers_are_sequential_and_zero_padded(): void
    {
        $token = $this->actingUserToken(['repayments.create']);
        $year = now()->year;

        $first = $this->repay($this->scheduleWithOutstanding(100), $token);
        $second = $this->repay($this->scheduleWithOutstanding(100), $token);

        $this->assertSame("RC-{$year}-000001", $first);
        $this->assertSame("RC-{$year}-000002", $second);
    }

    public function test_receipt_numbers_are_unique(): void
    {
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($this->scheduleWithOutstanding(100), $token);
        $this->repay($this->scheduleWithOutstanding(100), $token);
        $this->repay($this->scheduleWithOutstanding(100), $token);

        $receiptNumbers = Receipt::pluck('receipt_no')->all();

        $this->assertCount(3, array_unique($receiptNumbers));
    }
}
