<?php

namespace Tests\Feature\Dashboard;

use App\Models\Customer;
use App\Models\GracePeriod;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        GracePeriod::query()->first()->update(['duration' => 7, 'unit' => 'days']);
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

    /**
     * Repayment::factory() eagerly creates its own RepaymentSchedule (and
     * that schedule's own Loan) as a side effect of its definition(), even
     * when repayment_schedule_id is overridden — so it can't be used for
     * deterministic fixtures here. Build the Repayment directly instead.
     */
    private function createRepayment(RepaymentSchedule $schedule, float $amount, string $date): Repayment
    {
        $receipt = Receipt::factory()->create(['amount' => $amount, 'receipt_date' => $date]);

        return Repayment::create([
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'receipt_id' => $receipt->id,
            'amount' => $amount,
            'repayment_date' => $date,
            'received_by' => User::factory()->create()->id,
        ]);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/dashboard');

        $response->assertStatus(401);
    }

    public function test_dashboard_requires_dashboard_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_dashboard_returns_customer_metrics(): void
    {
        Customer::factory()->count(2)->create();
        Customer::factory()->inactive()->create();
        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.customers.total'));
        $this->assertSame(2, $response->json('data.customers.active'));
    }

    public function test_dashboard_returns_loan_metrics_including_overdue(): void
    {
        Loan::factory()->create(['status' => 'pending']);
        Loan::factory()->active()->create();
        Loan::factory()->completed()->create();

        $overdueLoan = Loan::factory()->active()->create(['principal_amount' => 500000]);
        RepaymentSchedule::factory()->create([
            'loan_id' => $overdueLoan->id,
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('data.loans.total'));
        $this->assertSame(2, $response->json('data.loans.active'));
        $this->assertSame(1, $response->json('data.loans.pending'));
        $this->assertSame(1, $response->json('data.loans.completed'));
        $this->assertSame(1, $response->json('data.loans.overdue'));
    }

    public function test_dashboard_returns_financial_metrics(): void
    {
        $loanA = Loan::factory()->active()->create(['principal_amount' => 1000000, 'interest_amount' => 220000]);
        $loanB = Loan::factory()->completed()->create(['principal_amount' => 500000, 'interest_amount' => 110000]);

        Payment::factory()->create(['loan_id' => $loanA->id, 'amount' => 1000000, 'payment_date' => now()->toDateString()]);
        Payment::factory()->create(['loan_id' => $loanB->id, 'amount' => 500000, 'payment_date' => now()->toDateString()]);

        $schedule = RepaymentSchedule::factory()->create([
            'loan_id' => $loanA->id,
            'total_amount' => 1220000,
            'outstanding_amount' => 1020000,
            'status' => 'partially_paid',
        ]);
        $this->createRepayment($schedule, 200000, now()->toDateString());

        Penalty::factory()->create([
            'loan_id' => $loanA->id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => 50000,
            'applied_date' => now()->toDateString(),
        ]);

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('1500000.00', $response->json('data.financial.total_amount_issued'));
        $this->assertSame('200000.00', $response->json('data.financial.total_amount_collected'));
        $this->assertSame('1020000.00', $response->json('data.financial.total_outstanding_amount'));
        $this->assertSame('330000.00', $response->json('data.financial.total_interest_generated'));
        $this->assertSame('50000.00', $response->json('data.financial.total_penalties_generated'));
    }

    public function test_dashboard_returns_repayment_metrics(): void
    {
        $loan = Loan::factory()->active()->create(['principal_amount' => 500000]);

        $overdueSchedule = RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'pending',
        ]);
        RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 2,
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'pending',
        ]);

        $this->createRepayment($overdueSchedule, 100000, now()->toDateString());

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.repayments.total'));
        $this->assertSame(1, $response->json('data.repayments.today'));
        $this->assertSame(1, $response->json('data.repayments.overdue'));
        $this->assertSame(1, $response->json('data.repayments.upcoming'));
    }

    public function test_dashboard_returns_payment_metrics(): void
    {
        $loanA = Loan::factory()->active()->create();
        $loanB = Loan::factory()->active()->create();

        Payment::factory()->create(['loan_id' => $loanA->id, 'payment_date' => now()->toDateString()]);
        Payment::factory()->create(['loan_id' => $loanB->id, 'payment_date' => now()->toDateString()]);

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.payments.total_disbursements'));
        $this->assertSame(2, $response->json('data.payments.today_disbursements'));
    }

    public function test_from_and_to_scope_event_dated_metrics_but_not_snapshot_metrics(): void
    {
        $loanA = Loan::factory()->active()->create();
        $loanB = Loan::factory()->active()->create();

        Payment::factory()->create(['loan_id' => $loanA->id, 'amount' => 1000000, 'payment_date' => '2026-08-01']);
        Payment::factory()->create(['loan_id' => $loanB->id, 'amount' => 500000, 'payment_date' => '2026-08-20']);

        RepaymentSchedule::factory()->create([
            'loan_id' => $loanA->id,
            'total_amount' => 1000000,
            'outstanding_amount' => 700000,
            'status' => 'partially_paid',
        ]);

        $token = $this->actingUserToken(['dashboard.view']);

        $scoped = $this->getJson(
            '/api/v1/dashboard?from=2026-08-01&to=2026-08-10',
            ['Authorization' => "Bearer {$token}"],
        );
        $scoped->assertStatus(200);
        $this->assertSame('1000000.00', $scoped->json('data.financial.total_amount_issued'));
        $this->assertSame(1, $scoped->json('data.payments.total_disbursements'));
        // Snapshot metric — unaffected by the date range.
        $this->assertSame('700000.00', $scoped->json('data.financial.total_outstanding_amount'));

        $unscoped = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);
        $unscoped->assertStatus(200);
        $this->assertSame('1500000.00', $unscoped->json('data.financial.total_amount_issued'));
        $this->assertSame(2, $unscoped->json('data.payments.total_disbursements'));
    }

    public function test_to_before_from_is_rejected(): void
    {
        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson(
            '/api/v1/dashboard?from=2026-08-20&to=2026-08-01',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['to']);
    }

    public function test_dashboard_executes_a_bounded_number_of_queries_regardless_of_data_volume(): void
    {
        Customer::factory()->count(20)->create();
        Loan::factory()->count(10)->create();
        $token = $this->actingUserToken(['dashboard.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            25,
            $queryCount,
            'Dashboard endpoint should use a small, fixed number of aggregate queries rather than looping per record.',
        );
    }
}
