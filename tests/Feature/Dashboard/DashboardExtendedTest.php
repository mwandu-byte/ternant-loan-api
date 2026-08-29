<?php

namespace Tests\Feature\Dashboard;

use App\Models\GracePeriod;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Penalty;
use App\Models\Receipt;
use App\Models\Repayment;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardExtendedTest extends TestCase
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

    public function test_period_defaults_to_trailing_30_days_ending_today(): void
    {
        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(now()->toDateString(), $response->json('data.period.date_to'));
        $this->assertSame(now()->subDays(29)->toDateString(), $response->json('data.period.date_from'));
    }

    public function test_period_echoes_back_an_explicitly_supplied_range(): void
    {
        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson(
            '/api/v1/dashboard?from=2026-01-01&to=2026-01-10',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('2026-01-01', $response->json('data.period.date_from'));
        $this->assertSame('2026-01-10', $response->json('data.period.date_to'));
    }

    public function test_loan_performance_and_charts_reflect_seeded_data(): void
    {
        $today = now()->startOfDay();

        $loanA = Loan::factory()->active()->create([
            'start_date' => $today->copy()->subDays(5)->toDateString(),
            'principal_amount' => 1000000,
            'interest_amount' => 100000,
        ]);
        $loanB = Loan::factory()->completed()->create([
            'start_date' => $today->copy()->subDays(10)->toDateString(),
            'principal_amount' => 500000,
            'interest_amount' => 50000,
        ]);

        Payment::factory()->create([
            'loan_id' => $loanA->id,
            'amount' => 1000000,
            'payment_date' => $today->copy()->subDays(4)->toDateString(),
        ]);
        Payment::factory()->create([
            'loan_id' => $loanB->id,
            'amount' => 500000,
            'payment_date' => $today->copy()->subDays(9)->toDateString(),
        ]);

        $scheduleA = RepaymentSchedule::factory()->create([
            'loan_id' => $loanA->id,
            'due_date' => $today->copy()->subDays(20)->toDateString(),
            'total_amount' => 1100000,
            'outstanding_amount' => 600000,
            'status' => 'partially_paid',
        ]);
        $this->createRepayment($scheduleA, 500000, $today->copy()->subDays(3)->toDateString());

        $scheduleB = RepaymentSchedule::factory()->create([
            'loan_id' => $loanB->id,
            'total_amount' => 550000,
            'outstanding_amount' => 0,
            'status' => 'paid',
        ]);

        Penalty::factory()->create([
            'loan_id' => $loanA->id,
            'repayment_schedule_id' => $scheduleA->id,
            'amount' => 20000,
            'applied_date' => $today->copy()->subDays(2)->toDateString(),
        ]);

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);

        $this->assertSame(2, $response->json('data.loan_performance.total_loans'));
        $this->assertSame(1, $response->json('data.loan_performance.active_loans'));
        $this->assertSame(1, $response->json('data.loan_performance.completed_loans'));
        $this->assertSame(1, $response->json('data.loan_performance.overdue_loans'));
        $this->assertEquals(100.0, $response->json('data.loan_performance.overdue_percentage'));
        $this->assertSame('1500000.00', $response->json('data.loan_performance.total_disbursed'));
        $this->assertSame('500000.00', $response->json('data.loan_performance.total_collected'));
        $this->assertSame('600000.00', $response->json('data.loan_performance.total_outstanding'));
        $this->assertSame('150000.00', $response->json('data.loan_performance.total_interest_generated'));
        $this->assertSame('20000.00', $response->json('data.loan_performance.total_penalties_accrued'));

        $loanStatusChart = collect($response->json('data.charts.loan_status'))->keyBy('status');
        $this->assertSame(0, $loanStatusChart['pending']['count']);
        $this->assertSame(1, $loanStatusChart['active']['count']);
        $this->assertSame(1, $loanStatusChart['completed']['count']);
        $this->assertSame(0, $loanStatusChart['cancelled']['count']);

        $paidVsOutstanding = collect($response->json('data.charts.paid_vs_outstanding'))->keyBy('category');
        $this->assertSame('1050000.00', $paidVsOutstanding['paid']['amount']);
        $this->assertSame('600000.00', $paidVsOutstanding['outstanding']['amount']);

        // Trend charts are bucketed monthly and the default 30-day window
        // can span one or two calendar months depending on today's date,
        // so assert the totals summed across all buckets rather than a
        // specific bucket's label — deterministic regardless of run date.
        $loansSummed = collect($response->json('data.charts.loan_performance_trend'))->sum('loans');
        $this->assertSame(2, $loansSummed);

        $disbursedSummed = collect($response->json('data.charts.disbursement_trend'))
            ->sum(fn ($row) => (float) $row['amount']);
        $disbursementsCountSummed = collect($response->json('data.charts.disbursement_trend'))->sum('disbursements');
        $this->assertSame(1500000.0, $disbursedSummed);
        $this->assertSame(2, $disbursementsCountSummed);

        $collectedSummed = collect($response->json('data.charts.collection_trend'))
            ->sum(fn ($row) => (float) $row['amount']);
        $repaymentsCountSummed = collect($response->json('data.charts.collection_trend'))->sum('repayments');
        $this->assertSame(500000.0, $collectedSummed);
        $this->assertSame(1, $repaymentsCountSummed);

        $penaltiesSummed = collect($response->json('data.charts.penalty_trend'))
            ->sum(fn ($row) => (float) $row['accrued']);
        $this->assertSame(20000.0, $penaltiesSummed);
    }

    public function test_penalty_trend_never_exposes_a_paid_or_outstanding_key(): void
    {
        Penalty::factory()->create([
            'amount' => 15000,
            'applied_date' => now()->toDateString(),
        ]);

        $token = $this->actingUserToken(['dashboard.view']);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);

        $bucketWithData = collect($response->json('data.charts.penalty_trend'))
            ->first(fn ($row) => (float) $row['accrued'] > 0);

        $this->assertNotNull($bucketWithData);
        $this->assertEqualsCanonicalizing(['period', 'accrued'], array_keys($bucketWithData));
    }

    public function test_dashboard_extended_sections_require_dashboard_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }
}
