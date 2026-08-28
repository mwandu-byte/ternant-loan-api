<?php

namespace Tests\Feature\Repayment;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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

    private function repayRaw(RepaymentSchedule $schedule, float $amount, string $token): TestResponse
    {
        return $this->postJson('/api/v1/repayments', [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => $amount,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * @param  array<int, float>  $totals  total_amount for installments 1..N, in order
     * @return array{0: Loan, 1: array<int, RepaymentSchedule>}
     */
    private function loanWithSchedules(array $totals, string $status = 'active'): array
    {
        $loan = Loan::factory()->create(['status' => $status]);
        $schedules = [];

        foreach (array_values($totals) as $index => $total) {
            $schedules[] = RepaymentSchedule::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $index + 1,
                'total_amount' => $total,
                'outstanding_amount' => $total,
                'status' => 'pending',
            ]);
        }

        return [$loan, $schedules];
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

    public function test_overpayment_rolls_forward_into_the_next_installment(): void
    {
        [, $schedules] = $this->loanWithSchedules([100000, 50000]);
        $token = $this->actingUserToken(['repayments.create']);

        // 120,000 against installment 1 (owes 100,000): fully pays it off
        // and rolls the remaining 20,000 onto installment 2 (owes 50,000).
        $response = $this->repayRaw($schedules[0], 120000, $token);
        $response->assertStatus(201);

        $repayments = $response->json('data.repayments');
        $this->assertCount(2, $repayments);
        $this->assertSame($schedules[0]->id, $repayments[0]['repayment_schedule_id']);
        $this->assertSame('100000.00', $repayments[0]['amount']);
        $this->assertSame($schedules[1]->id, $repayments[1]['repayment_schedule_id']);
        $this->assertSame('20000.00', $repayments[1]['amount']);
        $this->assertSame($repayments[0]['receipt_id'], $repayments[1]['receipt_id']);

        $this->assertSame('paid', $schedules[0]->fresh()->status);
        $this->assertSame('0.00', $schedules[0]->fresh()->outstanding_amount);
        $this->assertSame('partially_paid', $schedules[1]->fresh()->status);
        $this->assertSame('30000.00', $schedules[1]->fresh()->outstanding_amount);
    }

    public function test_overpayment_can_fully_settle_more_than_one_subsequent_installment(): void
    {
        [, $schedules] = $this->loanWithSchedules([50000, 50000, 50000]);
        $token = $this->actingUserToken(['repayments.create']);

        // 150,000 against installment 1 exactly settles all three.
        $response = $this->repayRaw($schedules[0], 150000, $token);
        $response->assertStatus(201);

        $repayments = $response->json('data.repayments');
        $this->assertCount(3, $repayments);

        foreach ($schedules as $schedule) {
            $this->assertSame('paid', $schedule->fresh()->status);
            $this->assertSame('0.00', $schedule->fresh()->outstanding_amount);
        }

        $receiptIds = array_unique(array_column($repayments, 'receipt_id'));
        $this->assertCount(1, $receiptIds);
    }

    public function test_paying_off_the_last_installment_completes_the_loan(): void
    {
        [$loan, $schedules] = $this->loanWithSchedules([100000, 100000]);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedules[0], 100000, $token);
        $this->assertSame('active', $loan->fresh()->status);

        $this->repay($schedules[1], 100000, $token);
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_paying_off_the_last_installment_via_rollover_completes_the_loan(): void
    {
        [$loan, $schedules] = $this->loanWithSchedules([100000, 50000]);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedules[0], 150000, $token);

        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertSame('paid', $schedules[1]->fresh()->status);
    }

    public function test_overpayment_beyond_the_entire_remaining_loan_balance_is_rejected(): void
    {
        [$loan, $schedules] = $this->loanWithSchedules([100000, 50000]);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->repayRaw($schedules[0], 150000.01, $token);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Repayment amount exceeds the outstanding balance for this installment and any subsequent unpaid installments on this loan.',
        ]);
        $this->assertDatabaseCount('repayments', 0);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertSame('pending', $schedules[0]->fresh()->status);
        $this->assertSame('pending', $schedules[1]->fresh()->status);
        $this->assertSame('active', $loan->fresh()->status);
    }

    public function test_targeting_an_already_paid_installment_is_rejected_even_when_a_later_one_has_room(): void
    {
        [, $schedules] = $this->loanWithSchedules([100000, 50000]);
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedules[0], 100000, $token);
        $this->assertSame('paid', $schedules[0]->fresh()->status);

        // Targeting the now-fully-paid installment 1 must not silently
        // redirect onto installment 2, even though it has room.
        $response = $this->repayRaw($schedules[0], 1, $token);

        $response->assertStatus(422);
        $this->assertSame('pending', $schedules[1]->fresh()->status);
        $this->assertDatabaseCount('repayments', 1);
    }

    public function test_a_pending_loan_is_never_auto_completed(): void
    {
        [$loan, $schedules] = $this->loanWithSchedules([100000], 'pending');
        $token = $this->actingUserToken(['repayments.create']);

        $this->repay($schedules[0], 100000, $token);

        $this->assertSame('pending', $loan->fresh()->status);
    }
}
