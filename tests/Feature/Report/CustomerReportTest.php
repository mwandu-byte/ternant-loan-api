<?php

namespace Tests\Feature\Report;

use App\Models\Customer;
use App\Models\GracePeriod;
use App\Models\Loan;
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

class CustomerReportTest extends TestCase
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

    public function test_customer_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/customers');

        $response->assertStatus(401);
    }

    public function test_customer_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/customers', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_customer_report_returns_correct_portfolio_information(): void
    {
        $customer = Customer::factory()->create(['status' => 'active']);
        Customer::factory()->inactive()->create();

        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id, 'principal_amount' => 500000]);
        $schedule = RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'total_amount' => 550000,
            'outstanding_amount' => 350000,
            'status' => 'partially_paid',
        ]);

        $receipt = Receipt::factory()->create(['amount' => 200000, 'receipt_date' => now()->toDateString()]);
        Repayment::create([
            'loan_id' => $loan->id, 'repayment_schedule_id' => $schedule->id, 'receipt_id' => $receipt->id,
            'amount' => 200000, 'repayment_date' => now()->toDateString(), 'received_by' => User::factory()->create()->id,
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/customers', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.summary.total_customers'));
        $this->assertSame(1, $response->json('data.summary.active_customers'));
        $this->assertSame(1, $response->json('data.summary.inactive_customers'));
        $this->assertSame(1, $response->json('data.summary.customers_with_active_loans'));

        $item = collect($response->json('data.items'))->firstWhere('phone', $customer->phone);
        $this->assertNotNull($item);
        $this->assertSame(1, $item['number_of_loans']);
        $this->assertSame('500000.00', $item['total_borrowed']);
        $this->assertSame('200000.00', $item['total_repaid']);
        $this->assertSame('350000.00', $item['outstanding_amount']);
    }

    public function test_customer_report_counts_customers_with_overdue_loans(): void
    {
        $customer = Customer::factory()->create();
        $loan = Loan::factory()->active()->create(['customer_id' => $customer->id]);
        RepaymentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'due_date' => now()->subDays(20)->toDateString(),
            'outstanding_amount' => 10000,
            'status' => 'pending',
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/customers', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.summary.customers_with_overdue_loans'));
    }

    public function test_customer_report_filters_by_status(): void
    {
        Customer::factory()->create(['status' => 'active']);
        Customer::factory()->inactive()->create();

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/customers?status=inactive', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('inactive', $response->json('data.items.0.status'));
    }

    public function test_customer_report_paginates(): void
    {
        Customer::factory()->count(3)->create();

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/customers?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_customer_report_executes_a_bounded_number_of_queries(): void
    {
        Customer::factory()->count(10)->create();
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/customers', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
