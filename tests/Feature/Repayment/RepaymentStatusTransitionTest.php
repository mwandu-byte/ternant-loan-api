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

class RepaymentStatusTransitionTest extends TestCase
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

    private function repay(RepaymentSchedule $schedule, float $amount, string $token): void
    {
        $this->postJson('/api/v1/repayments', [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => $amount,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);
    }

    public function test_partial_repayments_transition_status_pending_to_partial_to_paid(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedule, 100000, $token);
        $schedule->refresh();
        $this->assertSame('partially_paid', $schedule->status);
        $this->assertSame('200000.00', $schedule->outstanding_amount);

        $this->repay($schedule, 200000, $token);
        $schedule->refresh();
        $this->assertSame('paid', $schedule->status);
        $this->assertSame('0.00', $schedule->outstanding_amount);
    }

    public function test_full_single_repayment_transitions_directly_to_paid(): void
    {
        $schedule = $this->scheduleWithOutstanding(300000);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedule, 300000, $token);

        $schedule->refresh();
        $this->assertSame('paid', $schedule->status);
        $this->assertSame('0.00', $schedule->outstanding_amount);
    }

    public function test_outstanding_amount_is_exact_after_each_partial_repayment(): void
    {
        $schedule = $this->scheduleWithOutstanding(1000.00);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedule, 333.33, $token);
        $this->assertSame('666.67', $schedule->refresh()->outstanding_amount);

        $this->repay($schedule, 333.33, $token);
        $this->assertSame('333.34', $schedule->refresh()->outstanding_amount);

        $this->repay($schedule, 333.34, $token);
        $this->assertSame('0.00', $schedule->refresh()->outstanding_amount);
        $this->assertSame('paid', $schedule->status);
    }
}
