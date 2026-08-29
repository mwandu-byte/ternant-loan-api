<?php

namespace Tests\Feature\Report;

use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DisbursementReportTest extends TestCase
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

    public function test_disbursement_report_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/disbursements');

        $response->assertStatus(401);
    }

    public function test_disbursement_report_requires_reports_view_permission(): void
    {
        $token = $this->actingUserToken([]);

        $response = $this->getJson('/api/v1/reports/disbursements', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403);
    }

    public function test_disbursement_report_returns_payment_data_not_receipts(): void
    {
        $loan = Loan::factory()->active()->create();
        $disburser = User::factory()->create();
        Payment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 1000000,
            'payment_method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
            'paid_by' => $disburser->id,
        ]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/disbursements', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertSame('1000000.00', $response->json('data.summary.total_disbursed'));
        $this->assertSame(1, $response->json('data.summary.number_of_disbursements'));
        $this->assertSame('1000000.00', $response->json('data.summary.average_disbursement'));

        $item = $response->json('data.items.0');
        $this->assertSame($loan->reference_no, $item['loan_reference']);
        $this->assertSame('1000000.00', $item['amount']);
        $this->assertSame('bank_transfer', $item['payment_method']);
        $this->assertSame($disburser->name, $item['disbursed_by']);
    }

    public function test_disbursement_report_filters_by_user_id_and_date_range(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Payment::factory()->create(['paid_by' => $userA->id, 'payment_date' => now()->subDays(10)->toDateString()]);
        $recent = Payment::factory()->create(['paid_by' => $userB->id, 'payment_date' => now()->toDateString()]);

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson("/api/v1/reports/disbursements?user_id={$userB->id}", ['Authorization' => "Bearer {$token}"]);
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame($recent->reference_no, $response->json('data.items.0.payment_reference'));

        $response = $this->getJson(
            '/api/v1/reports/disbursements?date_from='.now()->subDays(1)->toDateString(),
            ['Authorization' => "Bearer {$token}"],
        );
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_disbursement_report_paginates(): void
    {
        Payment::factory()->count(3)->create();

        $token = $this->actingUserToken(['reports.view']);

        $response = $this->getJson('/api/v1/reports/disbursements?per_page=2', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
    }

    public function test_disbursement_report_executes_a_bounded_number_of_queries(): void
    {
        Payment::factory()->count(10)->create();
        $token = $this->actingUserToken(['reports.view']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/reports/disbursements', ['Authorization' => "Bearer {$token}"])->assertStatus(200);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queryCount);
    }
}
