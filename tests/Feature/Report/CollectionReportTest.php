<?php

namespace Tests\Feature\Report;

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

class CollectionReportTest extends TestCase
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

    /**
     * Repayment::factory() eagerly creates its own RepaymentSchedule (and
     * that schedule's own Loan) even when repayment_schedule_id is
     * overridden — build the Repayment directly instead.
     */
    private function createRepayment(RepaymentSchedule $schedule, float $amount, string $date, ?User $receivedBy = null, string $method = 'cash'): Repayment
    {
        $receipt = Receipt::factory()->create(['amount' => $amount, 'receipt_date' => $date, 'payment_method' => $method]);

        return Repayment::create([
            'loan_id' => $schedule->loan_id,
            'repayment_schedule_id' => $schedule->id,
            'receipt_id' => $receipt->id,
            'amount' => $amount,
            'repayment_date' => $date,
            'received_by' => ($receivedBy ?? User::factory()->create())->id,
        ]);
    }

    public function test_collection_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/collections');

        $response->assertStatus(401);
    }

    public function test_collection_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/collections', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_collection_report_sums_repayment_amount_not_receipt_amount(): void
    {
        // One receipt (a single customer payment of 300000) fans out
        // across two schedule installments — the report must sum the two
        // Repayment allocations (100000 + 200000), never double-count by
        // summing the Receipt.amount (300000) per allocation.
        $loan = Loan::factory()->active()->create();
        $scheduleA = RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 1]);
        $scheduleB = RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 2]);

        $receipt = Receipt::factory()->create(['amount' => 300000, 'receipt_date' => now()->toDateString()]);
        Repayment::create([
            'loan_id' => $loan->id, 'repayment_schedule_id' => $scheduleA->id, 'receipt_id' => $receipt->id,
            'amount' => 100000, 'repayment_date' => now()->toDateString(), 'received_by' => User::factory()->create()->id,
        ]);
        Repayment::create([
            'loan_id' => $loan->id, 'repayment_schedule_id' => $scheduleB->id, 'receipt_id' => $receipt->id,
            'amount' => 200000, 'repayment_date' => now()->toDateString(), 'received_by' => User::factory()->create()->id,
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/collections', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('300000.00', $response->json('data.summary.total_collected'));
        $this->assertSame(2, $response->json('data.summary.number_of_repayments'));
        $this->assertSame(1, $response->json('data.summary.total_receipts'));
    }

    public function test_collection_report_filters_by_user_id_and_date_range(): void
    {
        $collector = User::factory()->create();
        $schedule = RepaymentSchedule::factory()->create();
        $other = RepaymentSchedule::factory()->create();

        $this->createRepayment($schedule, 50000, now()->toDateString(), $collector);
        $this->createRepayment($other, 40000, now()->subDays(10)->toDateString());

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson("/api/v1/reports/collections?user_id={$collector->id}", ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame('50000.00', $response->json('data.items.0.amount'));

        $response = $this->getJson(
            '/api/v1/reports/collections?date_from='.now()->subDays(1)->toDateString(),
            ['Authorization' => "Bearer {$token}"],
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_collection_report_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createRepayment(RepaymentSchedule::factory()->create(), 10000, now()->toDateString());
        }

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/collections?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_collection_report_executes_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createRepayment(RepaymentSchedule::factory()->create(), 10000, now()->toDateString());
        }
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/collections', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
