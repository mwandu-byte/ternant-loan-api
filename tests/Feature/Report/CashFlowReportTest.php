<?php

namespace Tests\Feature\Report;

use App\Models\Loan;
use App\Models\Payment;
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

class CashFlowReportTest extends TestCase
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

    public function test_cash_flow_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/cash-flow');

        $response->assertStatus(401);
    }

    public function test_cash_flow_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/cash-flow', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_cash_flow_report_separates_money_in_and_money_out(): void
    {
        $loan = Loan::factory()->active()->create();
        Payment::factory()->create(['loan_id' => $loan->id, 'amount' => 1000000, 'payment_date' => now()->toDateString()]);

        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id]);
        $this->createRepayment($schedule, 300000, now()->toDateString());

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/cash-flow', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('300000.00', $response->json('data.summary.total_money_in'));
        $this->assertSame('1000000.00', $response->json('data.summary.total_money_out'));
        $this->assertSame('-700000.00', $response->json('data.summary.net_cash_flow'));

        $types = collect($response->json('data.items'))->pluck('type')->all();
        $this->assertContains('money_in', $types);
        $this->assertContains('money_out', $types);
        $this->assertCount(2, $types);
    }

    public function test_cash_flow_report_filters_by_type(): void
    {
        $loan = Loan::factory()->active()->create();
        Payment::factory()->create(['loan_id' => $loan->id, 'payment_date' => now()->toDateString()]);
        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id]);
        $this->createRepayment($schedule, 100000, now()->toDateString());

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/cash-flow?type=collection', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('money_in', $items[0]['type']);
    }

    public function test_cash_flow_report_filters_by_date_range(): void
    {
        $loanA = Loan::factory()->active()->create();
        $loanB = Loan::factory()->active()->create();
        Payment::factory()->create(['loan_id' => $loanA->id, 'amount' => 500000, 'payment_date' => now()->subDays(30)->toDateString()]);
        Payment::factory()->create(['loan_id' => $loanB->id, 'amount' => 200000, 'payment_date' => now()->toDateString()]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson(
            '/api/v1/reports/cash-flow?date_from='.now()->subDays(1)->toDateString().'&type=disbursement',
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertStatus(200);
        $this->assertSame('200000.00', $response->json('data.summary.total_money_out'));
    }

    public function test_cash_flow_report_paginates_across_both_sides(): void
    {
        $loan = Loan::factory()->active()->create();
        Payment::factory()->create(['loan_id' => $loan->id, 'payment_date' => now()->toDateString()]);
        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id]);
        $this->createRepayment($schedule, 100000, now()->toDateString());

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/cash-flow?per_page=1', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));
    }

    public function test_cash_flow_report_executes_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $loan = Loan::factory()->active()->create();
            Payment::factory()->create(['loan_id' => $loan->id]);
            $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id]);
            $this->createRepayment($schedule, 10000, now()->toDateString());
        }
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/cash-flow', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
