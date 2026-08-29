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

class RepaymentDecimalPrecisionTest extends TestCase
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

    public function test_decimal_amount_round_trips_exactly(): void
    {
        $schedule = $this->scheduleWithOutstanding(1234.56);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => 1234.56,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(201);
        $this->assertSame('1234.56', $response->json('data.repayments.0.amount'));
        $this->assertSame('1234.56', $response->json('data.repayments.0.receipt.amount'));
        $this->assertSame('0.00', $schedule->fresh()->outstanding_amount);
    }

    public function test_repeated_partial_repayments_never_drift_from_exact_totals(): void
    {
        $schedule = $this->scheduleWithOutstanding(100.00);
        $token = $this->actingUserToken(['repayments.create']);

        // Three repayments of 33.33 + 33.33 + 33.34 must sum exactly to
        // 100.00 with no floating-point drift accumulating across writes.
        foreach ([33.33, 33.33, 33.34] as $amount) {
            $this->postJson('/api/v1/repayments', [
                'loan_id' => $schedule->loan_id,
                'repayment_schedule_id' => $schedule->id,
                'amount' => $amount,
                'repayment_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);
        }

        $this->assertSame('0.00', $schedule->fresh()->outstanding_amount);
        $this->assertSame('paid', $schedule->fresh()->status);
        $sum = Repayment::where('repayment_schedule_id', $schedule->id)->sum('amount');
        $this->assertSame('100.00', number_format((float) $sum, 2, '.', ''));
    }

    public function test_three_decimal_places_are_rejected(): void
    {
        $schedule = $this->scheduleWithOutstanding(1234.567);
        $token = $this->actingUserToken(['repayments.create']);

        $response = $this->postJson('/api/v1/repayments', [
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => 1234.567,
            'repayment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }
}
