<?php

namespace Tests\Feature\Report;

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

class PenaltyReportTest extends TestCase
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

    public function test_penalty_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/penalties');

        $response->assertStatus(401);
    }

    public function test_penalty_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/penalties', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_penalty_report_returns_penalty_information_without_paid_or_outstanding_fields(): void
    {
        $loan = Loan::factory()->active()->create();
        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 2]);
        Penalty::factory()->create([
            'loan_id' => $loan->id,
            'repayment_schedule_id' => $schedule->id,
            'amount' => 25000,
            'applied_date' => now()->toDateString(),
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/penalties', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('25000.00', $response->json('data.summary.total_penalties_accrued'));
        $this->assertSame(1, $response->json('data.summary.number_of_penalized_loans'));

        $item = $response->json('data.items.0');
        $this->assertSame($loan->reference_no, $item['loan_reference']);
        $this->assertSame(2, $item['installment_number']);
        $this->assertSame('25000.00', $item['penalty_accrued']);
        $this->assertSame('applied', $item['penalty_status']);
        $this->assertArrayNotHasKey('penalty_paid', $item);
        $this->assertArrayNotHasKey('penalty_outstanding', $item);
    }

    public function test_penalty_report_filters_by_date_range_and_loan(): void
    {
        $loan = Loan::factory()->active()->create();
        $schedule = RepaymentSchedule::factory()->create(['loan_id' => $loan->id]);
        Penalty::factory()->create(['loan_id' => $loan->id, 'repayment_schedule_id' => $schedule->id, 'applied_date' => now()->subDays(30)->toDateString()]);

        $otherSchedule = RepaymentSchedule::factory()->create();
        Penalty::factory()->create(['loan_id' => $otherSchedule->loan_id, 'repayment_schedule_id' => $otherSchedule->id, 'applied_date' => now()->toDateString()]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson("/api/v1/reports/penalties?loan_id={$loan->id}", ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));

        $response = $this->getJson(
            '/api/v1/reports/penalties?date_from='.now()->subDays(1)->toDateString(),
            ['Authorization' => "Bearer {$token}"],
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_penalty_report_paginates(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Penalty::factory()->create();
        }

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/penalties?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_penalty_report_executes_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Penalty::factory()->create();
        }
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/penalties', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
