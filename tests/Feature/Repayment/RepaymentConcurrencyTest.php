<?php

namespace Tests\Feature\Repayment;

use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * True parallel-thread races aren't exercisable against SQLite `:memory:`
 * in this PHPUnit suite (one connection, one process). These tests instead
 * validate the mechanism that prevents a race — locking the schedule row
 * and recomputing total_repaid/outstanding from the repayments table
 * *after* the lock, inside the same transaction — by firing two
 * sequential requests against the same schedule's full outstanding
 * amount and confirming the second correctly sees the first's committed
 * state rather than a stale pre-commit balance.
 */
class RepaymentConcurrencyTest extends TestCase
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

    public function test_second_full_repayment_attempt_cannot_over_repay_a_schedule(): void
    {
        $loan = Loan::factory()->active()->create();
        $schedule = RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'total_amount' => 500000,
            'outstanding_amount' => 500000,
            'status' => 'pending',
        ]);
        $token = $this->actingUserToken(['repayments.create']);

        $payload = [
            'loan_id' => $loan->id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => 500000,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ];

        $first = $this->postJson('/api/v1/repayments', $payload, ['Authorization' => "Bearer {$token}"]);
        $first->assertStatus(201);

        $second = $this->postJson('/api/v1/repayments', $payload, ['Authorization' => "Bearer {$token}"]);
        $second->assertStatus(422)->assertJson([
            'success' => false,
            'message' => 'Repayment amount exceeds the outstanding balance for this installment and any subsequent unpaid installments on this loan.',
        ]);

        $this->assertDatabaseCount('repayments', 1);
        $this->assertSame('0.00', $schedule->fresh()->outstanding_amount);
        $this->assertSame('paid', $schedule->fresh()->status);
    }

    public function test_sum_of_sequential_partial_repayments_never_exceeds_the_schedule_total(): void
    {
        $loan = Loan::factory()->active()->create();
        $schedule = RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'total_amount' => 100000,
            'outstanding_amount' => 100000,
            'status' => 'pending',
        ]);
        $token = $this->actingUserToken(['repayments.create']);

        // Three attempts of 60,000 each against a 100,000 schedule: the
        // first two succeed (60,000 + 40,000 worth of headroom), the
        // third must be rejected once the second commits, never allowing
        // total repaid to exceed 100,000.
        $responses = [];

        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->postJson('/api/v1/repayments', [
                'loan_id' => $loan->id,
                'repayment_schedule_id' => $schedule->id,
                'amount' => 60000,
                'repayment_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ], ['Authorization' => "Bearer {$token}"]);
        }

        $statuses = array_map(fn ($r) => $r->getStatusCode(), $responses);

        $this->assertSame([201, 422, 422], $statuses);
        $this->assertSame('40000.00', $schedule->fresh()->outstanding_amount);

        $totalRepaid = Repayment::where('repayment_schedule_id', $schedule->id)->sum('amount');
        $this->assertLessThanOrEqual(100000.0, (float) $totalRepaid);
    }
}
