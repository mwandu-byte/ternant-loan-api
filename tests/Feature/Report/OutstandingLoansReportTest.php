<?php

namespace Tests\Feature\Report;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OutstandingLoansReportTest extends TestCase
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

    public function test_outstanding_loans_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/outstanding-loans');

        $response->assertStatus(401);
    }

    public function test_outstanding_loans_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/outstanding-loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_outstanding_loans_report_excludes_fully_paid_loans_by_default(): void
    {
        $outstandingLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create([
            'loan_id' => $outstandingLoan->id,
            'total_amount' => 100000,
            'outstanding_amount' => 60000,
            'status' => 'partially_paid',
        ]);

        // Status remains 'active' here so the exclusion is verified via
        // the outstanding_amount > 0 predicate itself, not merely via the
        // completed/cancelled status filter.
        $paidLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create([
            'loan_id' => $paidLoan->id,
            'total_amount' => 100000,
            'outstanding_amount' => 0,
            'status' => 'paid',
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/outstanding-loans', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($outstandingLoan->reference_no, $response->json('data.items.0.reference_no'));
        $this->assertSame('60000.00', $response->json('data.summary.total_outstanding'));
        $this->assertSame(1, $response->json('data.summary.number_of_outstanding_loans'));
    }

    public function test_outstanding_loans_report_filters_by_customer(): void
    {
        $loan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'outstanding_amount' => 10000, 'status' => 'pending']);

        $otherLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id, 'outstanding_amount' => 10000, 'status' => 'pending']);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson("/api/v1/reports/outstanding-loans?customer_id={$loan->customer_id}", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_outstanding_loans_report_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $loan = Loan::factory()->active()->create();
            RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'outstanding_amount' => 10000, 'status' => 'pending']);
        }

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/outstanding-loans?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_outstanding_loans_report_executes_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $loan = Loan::factory()->active()->create();
            RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'outstanding_amount' => 10000, 'status' => 'pending']);
        }
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/outstanding-loans', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
