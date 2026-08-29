<?php

namespace Tests\Feature\Report;

use App\Models\GracePeriod;
use App\Models\Loan;
use App\Models\Penalty;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OverdueLoansReportTest extends TestCase
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

    public function test_overdue_loans_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/overdue-loans');

        $response->assertStatus(401);
    }

    public function test_overdue_loans_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/overdue-loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_overdue_loans_report_uses_grace_period_aware_overdue_determination(): void
    {
        $overdueLoan = Loan::factory()->active()->create();
        $overdueSchedule = RepaymentSchedule::factory()->create([
            'loan_id' => $overdueLoan->id,
            'due_date' => now()->subDays(20)->toDateString(),
            'total_amount' => 100000,
            'outstanding_amount' => 100000,
            'status' => 'pending',
        ]);
        Penalty::factory()->create([
            'loan_id' => $overdueLoan->id,
            'repayment_schedule_id' => $overdueSchedule->id,
            'amount' => 15000,
        ]);

        // Due 3 days ago, but the 7-day grace period hasn't expired yet —
        // this must NOT be reported as overdue.
        $withinGraceLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create([
            'loan_id' => $withinGraceLoan->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'status' => 'pending',
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/overdue-loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($overdueLoan->reference_no, $response->json('data.items.0.loan_reference'));
        $this->assertGreaterThanOrEqual(13, $response->json('data.items.0.days_overdue'));
        $this->assertSame('15000.00', $response->json('data.items.0.penalty_amount'));

        $this->assertSame(1, $response->json('data.summary.overdue_loans_count'));
        $this->assertSame(1, $response->json('data.summary.overdue_installments_count'));
        $this->assertSame('100000.00', $response->json('data.summary.total_overdue_amount'));
        $this->assertSame('15000.00', $response->json('data.summary.total_accrued_penalties'));
    }

    public function test_overdue_loans_report_filters_by_customer(): void
    {
        $loan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'due_date' => now()->subDays(20)->toDateString(), 'status' => 'pending']);

        $otherLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id, 'due_date' => now()->subDays(20)->toDateString(), 'status' => 'pending']);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson("/api/v1/reports/overdue-loans?customer_id={$loan->customer_id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_overdue_loans_report_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $loan = Loan::factory()->active()->create();
            RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'due_date' => now()->subDays(20)->toDateString(), 'status' => 'pending']);
        }

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/overdue-loans?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_overdue_loans_report_executes_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $loan = Loan::factory()->active()->create();
            RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'due_date' => now()->subDays(20)->toDateString(), 'status' => 'pending']);
        }
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/overdue-loans', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
