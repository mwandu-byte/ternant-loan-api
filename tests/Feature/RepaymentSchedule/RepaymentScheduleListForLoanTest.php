<?php

namespace Tests\Feature\RepaymentSchedule;

use App\Models\Loan;
use App\Models\RepaymentSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepaymentScheduleListForLoanTest extends TestCase
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

    public function test_authorized_user_can_list_a_loans_repayment_schedule(): void
    {
        $loan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 1]);
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 2]);
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson("/api/v1/loans/{$loan->id}/repayment-schedules", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(2, $response->json('data.repayment_schedules'));
    }

    public function test_list_for_loan_requires_authentication(): void
    {
        $loan = Loan::factory()->active()->create();

        $response = $this->getJson("/api/v1/loans/{$loan->id}/repayment-schedules");

        $response->assertStatus(401);
    }

    public function test_list_for_loan_requires_repayments_view_permission(): void
    {
        $loan = Loan::factory()->active()->create();
        $token = $this->actingUserToken([]);

        $response = $this->getJson("/api/v1/loans/{$loan->id}/repayment-schedules", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(403)->assertJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
    }

    public function test_list_for_loan_returns_404_for_nonexistent_loan(): void
    {
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson('/api/v1/loans/999999/repayment-schedules', ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(404);
    }

    public function test_list_for_loan_only_returns_that_loans_schedule(): void
    {
        $loan = Loan::factory()->active()->create();
        $otherLoan = Loan::factory()->active()->create();
        RepaymentSchedule::factory()->create(['loan_id' => $loan->id, 'installment_number' => 1]);
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id, 'installment_number' => 1]);
        RepaymentSchedule::factory()->create(['loan_id' => $otherLoan->id, 'installment_number' => 2]);
        $token = $this->actingUserToken(['repayment-schedules.view']);

        $response = $this->getJson("/api/v1/loans/{$loan->id}/repayment-schedules", ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.repayment_schedules'));
        $this->assertSame($loan->id, $response->json('data.repayment_schedules.0.loan_id'));
    }
}
