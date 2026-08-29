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

class LoanPortfolioReportTest extends TestCase
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

    public function test_loan_portfolio_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/loan-portfolio');

        $response->assertStatus(401);
    }

    public function test_loan_portfolio_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/loan-portfolio', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_loan_portfolio_report_returns_correct_summary_and_items(): void
    {
        $loanA = Loan::factory()->active()->create([
            'principal_amount' => 1000000,
            'interest_amount' => 100000,
            'total_amount' => 1100000,
        ]);
        RepaymentSchedule::factory()->create([
            'loan_id' => $loanA->id,
            'total_amount' => 1100000,
            'outstanding_amount' => 600000,
            'status' => 'partially_paid',
        ]);

        Loan::factory()->create(['principal_amount' => 200000]);
        Loan::factory()->create(['principal_amount' => 300000]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/loan-portfolio', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.summary.total_loans'));
        $this->assertSame(1, $response->json('data.summary.active_loans'));
        $this->assertSame(2, $response->json('data.summary.pending_loans'));
        $this->assertSame('1500000.00', $response->json('data.summary.total_principal'));

        $item = collect($response->json('data.items'))->firstWhere('reference_no', $loanA->reference_no);
        $this->assertNotNull($item);
        $this->assertSame('500000.00', $item['paid_amount']);
        $this->assertSame('600000.00', $item['outstanding_amount']);
    }

    public function test_loan_portfolio_report_filters_by_status_and_customer(): void
    {
        $loanA = Loan::factory()->active()->create();
        Loan::factory()->create();

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/loan-portfolio?status=active', ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($loanA->reference_no, $response->json('data.items.0.reference_no'));

        $response = $this->getJson("/api/v1/reports/loan-portfolio?customer_id={$loanA->customer_id}", ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_loan_portfolio_report_filters_by_start_date_range(): void
    {
        Loan::factory()->create(['start_date' => now()->subDays(60)->toDateString()]);
        $recent = Loan::factory()->create(['start_date' => now()->toDateString()]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson(
            '/api/v1/reports/loan-portfolio?date_from='.now()->subDays(5)->toDateString(),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($recent->reference_no, $response->json('data.items.0.reference_no'));
    }

    public function test_loan_portfolio_report_paginates_and_caps_per_page(): void
    {
        Loan::factory()->count(3)->create();

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/loan-portfolio?per_page=2&page=1', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));

        $response = $this->getJson('/api/v1/reports/loan-portfolio?per_page=500', ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(100, $response->json('data.pagination.per_page'));
    }

    public function test_loan_portfolio_report_executes_a_bounded_number_of_queries(): void
    {
        Loan::factory()->count(10)->create();
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/loan-portfolio', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
